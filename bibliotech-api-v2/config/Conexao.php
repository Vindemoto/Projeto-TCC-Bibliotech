<?php

// CORREÇÃO: o PHP, sem isso, usa UTC como fuso padrão — mas o banco
// (e a escola) usa o horário de Brasília (-03). Entre 21h e 23h59 no
// horário local, já é "o dia seguinte" em UTC, então o PHP calculava
// data de hoje errada (um dia a mais) em tudo que usa `new DateTime()`
// ou `date()` sem fuso explícito: data de empréstimo, data de
// devolução, cálculo de dias de atraso/multa, renovação (RN02) etc.
// Setado aqui porque esse arquivo é carregado por quase toda página
// e pela API — um lugar só garante que PHP e banco concordem sobre
// "que dia é hoje".
date_default_timezone_set('America/Sao_Paulo');

/**
 * Classe Conexao
 *
 * Essa classe é a responsável por ligar o nosso código PHP com o
 * banco de dados MySQL (que vamos rodar pelo XAMPP).
 * Ela guarda os dados de acesso e tem uma função que devolve
 * a conexão pronta pra ser usada.
 */
class Conexao
{
    // Dados de acesso ao banco (os padrões do XAMPP local)
    private static string $host = "localhost";     // endereço do banco
    private static string $porta = "3306";          // porta padrão do MySQL
    private static string $banco = "biblioteca"; // nome do banco de dados
    private static string $usuario = "root";        // usuário padrão do XAMPP
    private static string $senha = "";              // senha padrão do XAMPP (vazia)

    // Aqui a gente guarda a conexão depois que ela for criada,
    // pra não precisar abrir de novo toda vez
    private static ?PDO $instancia = null;

    /**
     * Esse construtor é privado de propósito: assim ninguém consegue
     * criar essa classe do jeito normal (new Conexao()). A única forma
     * de usar é chamando a função getConexao() lá embaixo.
     */
    private function __construct()
    {
    }

    /**
     * Essa função devolve a conexão com o banco pronta pra usar.
     * Se a conexão ainda não existe, ela cria uma. Se já existe,
     * ela só devolve a que já estava aberta.
     *
     * @return PDO
     */
    public static function getConexao(): PDO
    {
        if (self::$instancia === null) {
            try {
                // Monta o "endereço completo" de acesso ao banco
                $dsn = "mysql:host=" . self::$host
                    . ";port=" . self::$porta
                    . ";dbname=" . self::$banco
                    . ";charset=utf8mb4";

                // Tenta abrir a conexão com o banco usando os dados acima
                self::$instancia = new PDO($dsn, self::$usuario, self::$senha, [
                    // Faz o PHP avisar com uma mensagem clara se der erro
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    // Faz os resultados virem como array (nome => valor)
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Necessário pra poder usar CALL de procedure (ex.:
                    // sp_criar_usuario) e ainda fazer outra consulta na
                    // mesma conexão depois, sem o erro "Cannot execute
                    // queries while other unbuffered queries are active"
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                ]);
            } catch (PDOException $e) {
                // Se der erro na conexão, mostra a mensagem na tela
                // (isso é só pra fase de teste; num sistema real isso
                // seria guardado num arquivo de log, não mostrado assim)
                die("Erro ao conectar ao banco de dados: " . $e->getMessage());
            }
        }

        return self::$instancia;
    }
}
