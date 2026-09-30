<?php
// lista_desejos.php — tela simples pra ver e gerenciar a lista de
// desejos (sem JSON, direto em HTML).
//
// Integração com a fila de reserva (reserva_fila): quando a pessoa
// adiciona um livro que está SEM nenhuma cópia disponível, ela
// também entra na fila de espera daquele livro. Quando alguém
// devolve (ou tem o pedido recusado, liberando uma cópia), a
// primeira pessoa da fila recebe uma notificação avisando que já
// pode solicitar.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/Csrf.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
$idUsuario = $_SESSION["id_usuario"];
$mensagem = "";

$tokenValido = csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

// Remover um livro da lista (veio do formulário de remover)
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["acao"] ?? "") === "remover") {
    $idLivro = (int) ($_POST["id_livro"] ?? 0);
    $stmt = $conexao->prepare("DELETE FROM lista_desejos WHERE id_usuario = :usuario AND id_livro = :livro");
    $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

    // Sair da lista de desejos também tira da fila de espera desse livro
    // (só remove quem ainda está esperando — não desfaz um aviso já enviado)
    $stmt = $conexao->prepare(
        "DELETE FROM reserva_fila WHERE id_usuario = :usuario AND id_livro = :livro AND atendida = 0"
    );
    $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

    $mensagem = "Livro removido da lista de desejos.";
}

// Adicionar um livro (veio do formulário de adicionar, lá embaixo)
if ($_SERVER["REQUEST_METHOD"] === "POST" && $tokenValido && ($_POST["acao"] ?? "") === "adicionar") {
    $idLivro = (int) ($_POST["id_livro"] ?? 0);

    $stmt = $conexao->prepare("SELECT id_livro, titulo, quantidade_disponivel FROM livros WHERE id_livro = :id");
    $stmt->execute([":id" => $idLivro]);
    $livro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$livro) {
        $mensagem = "Livro não encontrado.";
    } else {
        $stmt = $conexao->prepare("SELECT id_lista FROM lista_desejos WHERE id_usuario = :usuario AND id_livro = :livro");
        $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

        if ($stmt->fetch()) {
            $mensagem = "Esse livro já está na sua lista de desejos.";
        } else {
            $stmt = $conexao->prepare("INSERT INTO lista_desejos (id_usuario, id_livro) VALUES (:usuario, :livro)");
            $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);
            $mensagem = "Livro adicionado à lista de desejos.";

            // Sem cópia disponível agora? Entra também na fila de espera
            if ((int) $livro["quantidade_disponivel"] <= 0) {
                $stmt = $conexao->prepare(
                    "SELECT id_reserva FROM reserva_fila
                     WHERE id_usuario = :usuario AND id_livro = :livro AND atendida = 0"
                );
                $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

                if (!$stmt->fetch()) {
                    // Próxima posição livre, olhando só quem ainda está
                    // esperando (posições de quem já saiu não contam)
                    $stmt = $conexao->prepare(
                        "SELECT COALESCE(MAX(posicao_fila), 0) + 1 AS proxima
                         FROM reserva_fila WHERE id_livro = :livro AND atendida = 0"
                    );
                    $stmt->execute([":livro" => $idLivro]);
                    $proximaPosicao = (int) $stmt->fetchColumn();

                    $stmt = $conexao->prepare(
                        "INSERT INTO reserva_fila (id_usuario, id_livro, posicao_fila, atendida)
                         VALUES (:usuario, :livro, :posicao, 0)"
                    );
                    $stmt->execute([
                        ":usuario" => $idUsuario,
                        ":livro"   => $idLivro,
                        ":posicao" => $proximaPosicao,
                    ]);
                    $mensagem .= " Como não tem cópia disponível agora, você também entrou na fila de espera.";
                }
            }
        }
    }
}

// Busca a lista de desejos da pessoa, já trazendo a posição dela na
// fila de espera daquele livro, se houver (conta só quem também
// ainda está esperando, com posição igual ou menor que a dela)
$stmt = $conexao->prepare(
    "SELECT L.id_livro, L.titulo, A.nome_autor, L.quantidade_disponivel,
            (SELECT COUNT(*) FROM reserva_fila R2
             WHERE R2.id_livro = L.id_livro AND R2.atendida = 0
               AND R2.posicao_fila <= R1.posicao_fila) AS posicao_na_fila
     FROM lista_desejos D
     INNER JOIN livros L ON L.id_livro = D.id_livro
     INNER JOIN autores A ON A.id_autor = L.id_autor
     LEFT JOIN reserva_fila R1
            ON R1.id_livro = D.id_livro AND R1.id_usuario = D.id_usuario AND R1.atendida = 0
     WHERE D.id_usuario = :usuario
     ORDER BY D.criado_em DESC"
);
$stmt->execute([":usuario" => $idUsuario]);
$listaDesejos = $stmt->fetchAll();

// Busca todos os livros, pra montar a lista suspensa de adicionar
$todosLivros = $conexao->query("SELECT id_livro, titulo FROM livros ORDER BY titulo")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Lista de desejos - Biblioteca Virtual</title>
</head>
<body>
    <h2>Minha lista de desejos</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <table border="1" cellpadding="6">
        <tr>
            <th>Título</th>
            <th>Autor</th>
            <th>Disponíveis</th>
            <th>Fila de espera</th>
            <th></th>
        </tr>
        <?php foreach ($listaDesejos as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= $item["quantidade_disponivel"] ?></td>
                <td>
                    <?= $item["posicao_na_fila"] !== null
                        ? "Você é o(a) " . (int) $item["posicao_na_fila"] . "º da fila"
                        : "—" ?>
                </td>
                <td>
                    <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="acao" value="remover">
                        <input type="hidden" name="id_livro" value="<?= $item["id_livro"] ?>">
                        <button type="submit">Remover</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($listaDesejos)): ?>
            <tr><td colspan="5">Sua lista de desejos está vazia.</td></tr>
        <?php endif; ?>
    </table>

    <h3>Adicionar livro à lista</h3>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="acao" value="adicionar">
        <select name="id_livro">
            <?php foreach ($todosLivros as $livro): ?>
                <option value="<?= $livro["id_livro"] ?>">
                    <?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Adicionar</button>
    </form>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
