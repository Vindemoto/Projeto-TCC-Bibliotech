<?php
// relatorio.php — tela de relatórios pra bibliotecário, em HTML
// simples (sem JSON, sem código pra ninguém ver).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$tipo = $_SESSION["tipo_usuario"];
if ($tipo !== "bibliotecario" && $tipo !== "admin") {
    http_response_code(403);
    exit("Acesso restrito a bibliotecários.");
}

$conexao = Conexao::getConexao();
session_write_close();

// Qual relatório mostrar (vem de um link, ex: ?relatorio=populares)
$relatorioEscolhido = $_GET["relatorio"] ?? "populares";

if ($relatorioEscolhido === "emprestimos") {
    $titulo = "Empréstimos";
    $sql = "SELECT U.nome AS usuario, U.tipo_usuario, L.titulo,
                   E.data_emp, E.data_dev_prevista, E.data_dev,
                   CASE
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            INNER JOIN livros L ON L.id_livro = E.id_livro
            ORDER BY E.data_emp DESC";
} elseif ($relatorioEscolhido === "usuarios") {
    $titulo = "Usuários mais ativos";
    $sql = "SELECT U.nome, U.tipo_usuario, COUNT(E.id_emprestimo) AS total_emprestimos,
                   MAX(E.data_emp) AS ultima_atividade
            FROM usuario U
            INNER JOIN emprestimo E ON E.id_usuario = U.id_usuario
            GROUP BY U.id_usuario, U.nome, U.tipo_usuario
            ORDER BY total_emprestimos DESC
            LIMIT 20";
} else {
    $relatorioEscolhido = "populares";
    $titulo = "Livros mais populares";
    $sql = "SELECT L.titulo, A.nome_autor AS autor, COUNT(E.id_emprestimo) AS total_emprestimos
            FROM livros L
            INNER JOIN autores A ON A.id_autor = L.id_autor
            LEFT JOIN emprestimo E ON E.id_livro = L.id_livro
            GROUP BY L.id_livro, L.titulo, A.nome_autor
            ORDER BY total_emprestimos DESC
            LIMIT 20";
}

$linhas = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Relatórios - Biblioteca Virtual</title>
</head>
<body>
    <h2>Relatórios</h2>

    <p>
        <a href="?relatorio=populares">Livros populares</a> |
        <a href="?relatorio=emprestimos">Empréstimos</a> |
        <a href="?relatorio=usuarios">Usuários ativos</a>
    </p>

    <h3><?= htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") ?></h3>

    <table border="1" cellpadding="6">
        <tr>
            <?php foreach (array_keys($linhas[0] ?? []) as $coluna): ?>
                <th><?= htmlspecialchars($coluna, ENT_QUOTES, "UTF-8") ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($linhas as $linha): ?>
            <tr>
                <?php foreach ($linha as $valor): ?>
                    <td><?= htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8") ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
