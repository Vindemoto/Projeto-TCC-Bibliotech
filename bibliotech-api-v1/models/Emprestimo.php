<?php

/**
 * Classe Emprestimo
 *
 * Cuida do fluxo de empréstimo com aprovação da bibliotecária:
 * 1) solicitarEmprestimo() — aluno/professor pede o livro, que fica
 *    reservado (ninguém mais pega) esperando aprovação
 * 2) aprovarSolicitacao() — bibliotecária libera de vez, e só aí
 *    o prazo de devolução começa a contar
 * 3) recusarSolicitacao() — bibliotecária nega, a cópia volta pro
 *    estoque disponível
 * 4) registrarDevolucao() — quando o livro emprestado é devolvido
 *
 * Ela não abre conexão sozinha — recebe uma conexão PDO já pronta
 * (a mesma que a Conexao::getConexao() devolve).
 */
class Emprestimo
{
    private PDO $conexao;

    // Regras de negócio fixas do módulo
    private const LIMITE_LIVROS_SIMULTANEOS = 3;
    private const PRAZO_DIAS_ALUNO = 15;
    private const PRAZO_DIAS_PROFESSOR = 30;

    public function __construct(PDO $conexaoPdo)
    {
        $this->conexao = $conexaoPdo;
    }

    /**
     * Passo 1: aluno ou professor solicita um livro. Fica reservado
     * (emp_ativo = 0, reserva = 1) até a bibliotecária decidir.
     */
    public function solicitarEmprestimo(int $idUsuario, int $idLivro): array
    {
        try {
            $this->conexao->beginTransaction();

            // 1) O usuário existe e está ativo?
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario, tipo_usuario, ativo FROM usuario WHERE id_usuario = :id"
            );
            $stmt->execute([':id' => $idUsuario]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Usuário não encontrado.');
            }

            if ((int) $usuario['ativo'] === 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Conta desativada. Não é possível solicitar empréstimos.');
            }

            // 2) Só aluno e professor podem solicitar
            $tipo = $usuario['tipo_usuario'];
            if ($tipo !== 'aluno' && $tipo !== 'professor') {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Apenas alunos e professores podem solicitar empréstimos.');
            }

            // 2.1) RN06: só aluno com matrícula ativa pode emprestar
            // (situacao diferente de "Ativo" — trancado, formado ou
            // evadido — não empresta, mesmo com a conta de login ainda
            // funcionando)
            if ($tipo === 'aluno') {
                $stmt = $this->conexao->prepare(
                    "SELECT situacao FROM aluno WHERE id_usuario = :id"
                );
                $stmt->execute([':id' => $idUsuario]);
                $situacao = $stmt->fetchColumn();

                if ($situacao !== 'Ativo') {
                    $this->conexao->rollBack();
                    return $this->resposta(
                        false,
                        'Sua matrícula não está ativa (situação: ' . ($situacao ?: 'não cadastrada') . '). Procure a secretaria.'
                    );
                }
            }

            // 2.5) A pessoa tem algum livro atrasado (ainda não
            // devolvido, passou do prazo) ou alguma multa pendente
            // de pagar? Se sim, não pode pegar livro novo
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) AS total FROM emprestimo
                 WHERE id_usuario = :id
                   AND (
                       (emp_ativo = 1 AND data_dev IS NULL AND data_dev_prevista < CURDATE())
                       OR multa_pendente = 1
                   )"
            );
            $stmt->execute([':id' => $idUsuario]);
            if ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                $this->conexao->rollBack();
                return $this->resposta(
                    false,
                    'Você tem um livro atrasado ou uma multa pendente. Regularize antes de solicitar outro empréstimo.'
                );
            }

            // 3) Limite de livros (conta os já emprestados MAIS os
            // que já estão esperando aprovação, pra não deixar
            // acumular pedido demais)
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) AS total FROM emprestimo
                 WHERE id_usuario = :id AND (emp_ativo = 1 OR reserva = 1)"
            );
            $stmt->execute([':id' => $idUsuario]);
            $totalAtivos = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($totalAtivos >= self::LIMITE_LIVROS_SIMULTANEOS) {
                $this->conexao->rollBack();
                return $this->resposta(
                    false,
                    'Limite de ' . self::LIMITE_LIVROS_SIMULTANEOS . ' livros (emprestados ou pendentes) atingido.'
                );
            }

            // 4) O livro existe e tem cópia disponível?
            $stmt = $this->conexao->prepare(
                "SELECT id_livro, titulo, quantidade_disponivel FROM livros WHERE id_livro = :id"
            );
            $stmt->execute([':id' => $idLivro]);
            $livro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$livro) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Livro não encontrado.');
            }

            if ((int) $livro['quantidade_disponivel'] <= 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Livro indisponível no momento.');
            }

            // 4.5) A pessoa já tem esse MESMO livro emprestado ou
            // pendente? Não pode duplicar o mesmo título (mas pode
            // ter outros livros diferentes, até o limite de 3)
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) AS total FROM emprestimo
                 WHERE id_usuario = :usuario AND id_livro = :livro AND (emp_ativo = 1 OR reserva = 1)"
            );
            $stmt->execute([':usuario' => $idUsuario, ':livro' => $idLivro]);
            if ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Você já tem esse livro emprestado ou pendente de aprovação.');
            }

            // 5) Cria a solicitação. As datas ainda não valem nada de
            // verdade (a coluna é obrigatória no banco) — só quando
            // a bibliotecária aprovar é que elas ficam certas.
            $hoje = (new DateTime())->format('Y-m-d');
            $stmt = $this->conexao->prepare(
                "INSERT INTO emprestimo
                    (emp_ativo, reserva, dev_pdia, multa_pendente, data_emp, data_dev_prevista, data_dev, id_livro, id_usuario)
                 VALUES
                    (0, 1, 0, 0, :data, :data, NULL, :id_livro, :id_usuario)"
            );
            $stmt->execute([
                ':data'       => $hoje,
                ':id_livro'   => $idLivro,
                ':id_usuario' => $idUsuario,
            ]);
            $idEmprestimo = (int) $this->conexao->lastInsertId();

            // 6) Reserva a cópia (some do estoque disponível, mesmo
            // sem estar emprestada de verdade ainda)
            $stmt = $this->conexao->prepare(
                "UPDATE livros SET quantidade_disponivel = quantidade_disponivel - 1 WHERE id_livro = :id"
            );
            $stmt->execute([':id' => $idLivro]);

            // 7) Avisa toda bibliotecária ativa, na hora
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario FROM usuario WHERE tipo_usuario = 'bibliotecario' AND ativo = 1"
            );
            $stmt->execute();
            $bibliotecarios = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $mensagemAviso = "{$usuario['tipo_usuario']} solicitou o livro \"{$livro['titulo']}\". Aguardando aprovação.";
            $inserirNotificacao = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'solicitacao_emprestimo', 'Nova solicitação de empréstimo', :mensagem)"
            );
            foreach ($bibliotecarios as $idBibliotecario) {
                $inserirNotificacao->execute([
                    ':id_usuario' => $idBibliotecario,
                    ':mensagem'   => $mensagemAviso,
                ]);
            }

            // 8) Avisa quem solicitou, confirmando que o pedido foi
            // enviado (ainda esperando decisão da bibliotecária)
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'solicitacao_enviada', 'Solicitação enviada',
                         :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $idUsuario,
                ':mensagem' => "Sua solicitação do livro \"{$livro['titulo']}\" foi enviada e está aguardando aprovação da bibliotecária.",
            ]);

            $this->conexao->commit();

            return $this->resposta(true, 'Solicitação enviada. Aguardando aprovação da bibliotecária.', $idEmprestimo);
        } catch (PDOException $e) {
            $this->conexao->rollBack();
            // Se duas pessoas pedirem o último exemplar quase ao
            // mesmo tempo, o banco (não nosso código) é quem garante
            // que só uma consiga — a outra cai aqui
            if ($e->getCode() === "23000" && str_contains($e->getMessage(), "chk_qtd_disponivel")) {
                return $this->resposta(false, 'Esse livro acabou de ser reservado por outra pessoa. Tente novamente.');
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao solicitar empréstimo. Tente novamente.');
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao solicitar empréstimo: ' . $e->getMessage());
        }
    }

    /**
     * Passo 2 (caminho "sim"): bibliotecária aprova a solicitação.
     * Só agora o prazo de devolução é calculado de verdade.
     */
    public function aprovarSolicitacao(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.reserva, E.emp_ativo, E.id_usuario, U.tipo_usuario, L.id_livro, L.titulo
                 FROM emprestimo E
                 INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$solicitacao || (int) $solicitacao['reserva'] !== 1 || (int) $solicitacao['emp_ativo'] !== 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Solicitação não encontrada ou já foi decidida antes.');
            }

            $prazoDias = ($solicitacao['tipo_usuario'] === 'professor')
                ? self::PRAZO_DIAS_PROFESSOR
                : self::PRAZO_DIAS_ALUNO;

            $dataEmprestimo = new DateTime();
            $dataPrevista = (clone $dataEmprestimo)->modify('+' . $prazoDias . ' days');

            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo
                 SET reserva = 0, emp_ativo = 1, data_emp = :data_emp, data_dev_prevista = :data_dev_prevista
                 WHERE id_emprestimo = :id"
            );
            $stmt->execute([
                ':data_emp'          => $dataEmprestimo->format('Y-m-d'),
                ':data_dev_prevista' => $dataPrevista->format('Y-m-d'),
                ':id'                => $idEmprestimo,
            ]);

            // Avisa quem pediu que foi aprovado
            $mensagem = "Seu pedido do livro \"{$solicitacao['titulo']}\" foi aprovado. "
                . "Devolução prevista para " . $dataPrevista->format('d/m/Y') . ".";
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_aprovado', 'Empréstimo aprovado', :mensagem)"
            );
            $stmt->execute([':id_usuario' => $solicitacao['id_usuario'], ':mensagem' => $mensagem]);

            $this->conexao->commit();

            return $this->resposta(true, 'Solicitação aprovada. Empréstimo liberado.', $idEmprestimo);
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao aprovar solicitação: ' . $e->getMessage());
        }
    }

    /**
     * Passo 2 (caminho "não"): bibliotecária recusa a solicitação.
     * A cópia volta pro estoque disponível, e o pedido é removido
     * (nunca chegou a virar empréstimo de verdade).
     */
    public function recusarSolicitacao(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.reserva, E.emp_ativo, E.id_usuario, E.id_livro, L.titulo
                 FROM emprestimo E
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$solicitacao || (int) $solicitacao['reserva'] !== 1 || (int) $solicitacao['emp_ativo'] !== 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Solicitação não encontrada ou já foi decidida antes.');
            }

            $stmt = $this->conexao->prepare("DELETE FROM emprestimo WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);

            $stmt = $this->conexao->prepare(
                "UPDATE livros SET quantidade_disponivel = quantidade_disponivel + 1 WHERE id_livro = :id"
            );
            $stmt->execute([':id' => $solicitacao['id_livro']]);

            // Avisa quem pediu que foi recusado
            $mensagem = "Seu pedido do livro \"{$solicitacao['titulo']}\" foi recusado pela bibliotecária.";
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_recusado', 'Empréstimo recusado', :mensagem)"
            );
            $stmt->execute([':id_usuario' => $solicitacao['id_usuario'], ':mensagem' => $mensagem]);

            // A recusa também liberou uma cópia — avisa quem estiver esperando
            $this->notificarProximoDaFila($solicitacao['id_livro']);

            $this->conexao->commit();

            return $this->resposta(true, 'Solicitação recusada.');
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao recusar solicitação: ' . $e->getMessage());
        }
    }

    /**
     * Registra a devolução de um livro já emprestado (aprovado).
     */
    public function registrarDevolucao(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT id_emprestimo, emp_ativo, id_livro, id_usuario, data_dev_prevista
                 FROM emprestimo WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Empréstimo não encontrado.');
            }

            if ((int) $emprestimo['emp_ativo'] === 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Este empréstimo já foi devolvido anteriormente.');
            }

            $dataDevolucao = new DateTime();
            $dataDevolucao->setTime(0, 0, 0); // zera a hora: só o dia importa pra calcular atraso
            $dataPrevista = new DateTime($emprestimo['data_dev_prevista']);

            // Calcula quantos dias passou do prazo (0 se devolveu em dia
            // — inclusive se devolveu no PRÓPRIO dia do vencimento, que
            // não conta como atraso)
            $diasAtraso = 0;
            $multaPendente = 0;
            $valorMulta = 0;
            if ($dataDevolucao > $dataPrevista) {
                $diasAtraso = $dataPrevista->diff($dataDevolucao)->days;
                $multaPendente = 1;

                // Pega o valor de multa por dia configurado (hoje R$ 1,00,
                // mas pode mudar sem precisar mexer no código)
                $valorDiaria = (float) $this->conexao
                    ->query("SELECT valor_multa_diaria FROM configuracao ORDER BY id_configuracao LIMIT 1")
                    ->fetchColumn();
                $valorMulta = round($diasAtraso * $valorDiaria, 2);
            }

            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo
                 SET emp_ativo = 0, data_dev = :data_dev, dev_pdia = :dias_atraso, multa_pendente = :multa, valor_multa = :valor
                 WHERE id_emprestimo = :id"
            );
            $stmt->execute([
                ':data_dev'    => $dataDevolucao->format('Y-m-d'),
                ':dias_atraso' => $diasAtraso,
                ':multa'       => $multaPendente,
                ':valor'       => $valorMulta,
                ':id'          => $idEmprestimo,
            ]);

            $stmt = $this->conexao->prepare(
                "UPDATE livros SET quantidade_disponivel = quantidade_disponivel + 1 WHERE id_livro = :id"
            );
            $stmt->execute([':id' => $emprestimo['id_livro']]);

            // Se devolveu atrasado, avisa a pessoa da multa
            if ($multaPendente === 1) {
                $valorFormatado = number_format($valorMulta, 2, ',', '.');
                $stmt = $this->conexao->prepare(
                    "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                     VALUES (:id_usuario, 'multa_gerada', 'Multa por atraso',
                             :mensagem)"
                );
                $stmt->execute([
                    ':id_usuario' => $emprestimo['id_usuario'],
                    ':mensagem' => "Você devolveu um livro com {$diasAtraso} dia(s) de atraso. Multa: R$ {$valorFormatado}. Regularize com a bibliotecária antes de solicitar outro empréstimo.",
                ]);
            }

            // A devolução liberou uma cópia — avisa quem estiver esperando
            $this->notificarProximoDaFila($emprestimo['id_livro']);

            $this->conexao->commit();

            return $this->resposta(true, 'Devolução registrada com sucesso.');
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao processar devolução: ' . $e->getMessage());
        }
    }

    /**
     * Bibliotecária marca uma multa como paga (quitada).
     */
    public function quitarMulta(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT id_emprestimo, id_usuario, multa_pendente FROM emprestimo WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Empréstimo não encontrado.');
            }

            if ((int) $emprestimo['multa_pendente'] === 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Esse empréstimo não tem multa pendente.');
            }

            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo SET multa_pendente = 0, valor_multa = 0 WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);

            // Avisa a pessoa que a multa foi paga
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'multa_quitada', 'Multa quitada',
                         'Sua multa foi registrada como paga. Obrigado por regularizar!')"
            );
            $stmt->execute([':id_usuario' => $emprestimo['id_usuario']]);

            $this->conexao->commit();

            return $this->resposta(true, 'Multa quitada com sucesso.');
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao quitar multa: ' . $e->getMessage());
        }
    }

    /**
     * RN02: renova o prazo de um empréstimo ativo, uma única vez,
     * desde que ele não esteja atrasado e ninguém esteja esperando
     * aquele livro na fila. Quem pode renovar: a própria pessoa que
     * pegou o livro, ou a bibliotecária/admin.
     */
    public function renovarEmprestimo(int $idEmprestimo, int $idUsuarioSolicitante): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.id_usuario, E.id_livro, E.emp_ativo,
                        E.data_emp, E.data_dev_prevista, E.data_dev,
                        U.tipo_usuario, L.titulo
                 FROM emprestimo E
                 INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo || (int) $emprestimo['emp_ativo'] === 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Empréstimo não encontrado ou já devolvido.');
            }

            // Só o dono do empréstimo, ou a bibliotecária/admin, pode renovar
            if ((int) $emprestimo['id_usuario'] !== $idUsuarioSolicitante) {
                $stmt = $this->conexao->prepare("SELECT tipo_usuario FROM usuario WHERE id_usuario = :id");
                $stmt->execute([':id' => $idUsuarioSolicitante]);
                $tipoSolicitante = $stmt->fetchColumn();

                if (!in_array($tipoSolicitante, ['bibliotecario', 'admin'], true)) {
                    $this->conexao->rollBack();
                    return $this->resposta(false, 'Você só pode renovar os seus próprios empréstimos.');
                }
            }

            if ($emprestimo['data_dev_prevista'] < date('Y-m-d')) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Não é possível renovar um empréstimo atrasado. Devolva o livro primeiro.');
            }

            $prazoDias = ($emprestimo['tipo_usuario'] === 'professor')
                ? self::PRAZO_DIAS_PROFESSOR
                : self::PRAZO_DIAS_ALUNO;

            // Se a diferença entre a data prevista e a data do
            // empréstimo já é de 2 prazos (ou mais), essa renovação já
            // usou a única disponível
            $stmt = $this->conexao->prepare(
                "SELECT DATEDIFF(:prevista, :emp) AS dias_totais"
            );
            $stmt->execute([':prevista' => $emprestimo['data_dev_prevista'], ':emp' => $emprestimo['data_emp']]);
            $diasTotais = (int) $stmt->fetchColumn();

            if ($diasTotais >= ($prazoDias * 2)) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Este empréstimo já foi renovado uma vez. Não é possível renovar de novo.');
            }

            // Ninguém pode estar esperando esse livro na fila
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM reserva_fila WHERE id_livro = :id_livro AND atendida = 0"
            );
            $stmt->execute([':id_livro' => $emprestimo['id_livro']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Este livro tem gente esperando na fila. Não é possível renovar — devolva no prazo.');
            }

            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo SET data_dev_prevista = DATE_ADD(data_dev_prevista, INTERVAL :dias DAY)
                 WHERE id_emprestimo = :id"
            );
            $stmt->execute([':dias' => $prazoDias, ':id' => $idEmprestimo]);

            $stmt = $this->conexao->prepare("SELECT data_dev_prevista FROM emprestimo WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);
            $novaData = $stmt->fetchColumn();

            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_renovado', 'Empréstimo renovado',
                         :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $emprestimo['id_usuario'],
                ':mensagem' => "Seu empréstimo do livro \"{$emprestimo['titulo']}\" foi renovado. Nova data de devolução: "
                    . (new DateTime($novaData))->format('d/m/Y') . '.',
            ]);

            $this->conexao->commit();

            return $this->resposta(true, 'Empréstimo renovado com sucesso.', $idEmprestimo);
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao renovar empréstimo: ' . $e->getMessage());
        }
    }

    /**
     * Toda vez que uma cópia de um livro volta a ficar disponível
     * (devolução ou recusa de pedido), confere se tem alguém
     * esperando na fila de reserva (lista de desejos) e avisa a
     * primeira pessoa da fila. Não reserva a cópia pra ela — é só
     * um aviso; quem chegar primeiro em "Solicitar" leva.
     */
    private function notificarProximoDaFila(int $idLivro): void
    {
        $stmt = $this->conexao->prepare(
            "SELECT id_reserva, id_usuario FROM reserva_fila
             WHERE id_livro = :id_livro AND atendida = 0
             ORDER BY posicao_fila ASC LIMIT 1"
        );
        $stmt->execute([':id_livro' => $idLivro]);
        $proximo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proximo) {
            return; // ninguém esperando esse livro
        }

        $stmt = $this->conexao->prepare(
            "UPDATE reserva_fila SET atendida = 1 WHERE id_reserva = :id"
        );
        $stmt->execute([':id' => $proximo['id_reserva']]);

        $stmt = $this->conexao->prepare("SELECT titulo FROM livros WHERE id_livro = :id");
        $stmt->execute([':id' => $idLivro]);
        $titulo = $stmt->fetchColumn();

        $stmt = $this->conexao->prepare(
            "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
             VALUES (:id_usuario, 'reserva_disponivel', 'Livro disponível',
                     :mensagem)"
        );
        $stmt->execute([
            ':id_usuario' => $proximo['id_usuario'],
            ':mensagem'   => "O livro \"{$titulo}\" que você estava esperando já tem cópia disponível! Corra pra solicitar antes que outra pessoa pegue.",
        ]);
    }

    private function resposta(bool $sucesso, string $mensagem, ?int $idEmprestimo = null): array
    {
        return [
            'sucesso'       => $sucesso,
            'mensagem'      => $mensagem,
            'id_emprestimo' => $idEmprestimo,
        ];
    }
}
