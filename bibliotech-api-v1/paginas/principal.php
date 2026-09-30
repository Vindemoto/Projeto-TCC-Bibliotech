<?php
// principal.php — página que aparece depois do login.
// Ainda "crua" (sem estilo), só pra mostrar que a sessão e os
// dados certos aparecem pra cada tipo de usuário.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";

// Se ninguém fez login, manda de volta pro login
if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
$idUsuario = $_SESSION["id_usuario"];
$nome = $_SESSION["nome"];
$tipo = $_SESSION["tipo_usuario"];

// Já pegamos tudo que precisávamos da sessão — libera a trava agora,
// em vez de segurar até o fim da página (isso é o que evita a
// página "travar" quando duas abas/telas usam a sessão ao mesmo tempo)
session_write_close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página principal — Bibliotech</title>
    <link rel="stylesheet" href="./css/principal.css">

</head>
<body>

    <!--BACKGROUND RENDER-->

    <div class="RENDER BACKGROUND" aria-hidden="true">
    <img src="./source/Arthur/home/Group 631.png" alt="">
    </div>

    <!--LOGO--> 

    <div class="principal-logo">
    <a href="principal.php" aria-label="Página inicial">
    <img src="./source/image/logo.png" alt="BIBLIOTECH">
    </a>
    </div>

    <!--código original do backend-->

    <h1>Bem-vindo(a), <?= htmlspecialchars($nome, ENT_QUOTES, "UTF-8") ?></h1>
    <p>Tipo de conta: <?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?></p>

    <?php if ($tipo === "aluno"): ?>
        <section>
            <h2>Área do aluno</h2>
            <p><a href="livros.php">Ver acervo de livros</a></p>
            <p><a href="meus_emprestimos.php">Meus empréstimos</a></p>
            <p><a href="lista_desejos.php">Minha lista de desejos</a></p>
        </section>
    <?php elseif ($tipo === "professor"): ?>
        <section>
            <h2>Área do professor</h2>
            <p><a href="livros.php">Ver acervo de livros</a></p>
            <p><a href="meus_emprestimos.php">Meus empréstimos</a></p>
            <p><a href="lista_desejos.php">Minha lista de desejos</a></p>
        </section>
    <?php elseif ($tipo === "bibliotecario"): ?>
        <?php
        $totalPendentes = $conexao->query(
            "SELECT COUNT(*) AS total FROM emprestimo WHERE reserva = 1 AND emp_ativo = 0"
        )->fetch()["total"];
        ?>
        <section>
            <h2>Área do bibliotecário</h2>
            <p><a href="livros.php">Ver acervo</a></p>
            <p><a href="aprovacoes.php">Solicitações pendentes (<?= $totalPendentes ?>)</a></p>
            <p><a href="devolucoes.php">Registrar devolução</a></p>
            <p><a href="multas.php">Multas pendentes</a></p>
            <p><a href="relatorio.php">Ver relatórios</a></p>
        </section>
    <?php else: ?>
        <section>
            <h2>Área do administrador</h2>
        </section>
    <?php endif; ?>

    <?php
    $totalNaoLidas = $conexao->prepare("SELECT COUNT(*) AS total FROM notificacao WHERE id_usuario = :id AND lida = 0");
    $totalNaoLidas->execute([":id" => $idUsuario]);
    $totalNaoLidas = $totalNaoLidas->fetch()["total"];
    ?>
    <p><a href="notificacoes.php">Minhas notificações (<?= $totalNaoLidas ?> não lidas)</a></p>
    <p><a href="logout.php">Sair</a></p>
</body>
</html>
