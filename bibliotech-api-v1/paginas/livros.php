<?php
// livros.php — lista simples do acervo (título, autor, categoria,
// quantas cópias tem disponível). Qualquer pessoa logada pode ver.

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
$tipo = $_SESSION["tipo_usuario"];
$ehBibliotecario = ($tipo === "bibliotecario" || $tipo === "admin");
$podeSolicitar = ($tipo === "aluno" || $tipo === "professor");

// Gera o token CSRF (se ainda não tiver um) ANTES de fechar a
// sessão, porque depois de fechada não dá mais pra gravar nela
$tokenCsrf = csrf_token();
session_write_close();

$sql = "SELECT L.id_livro, L.titulo, A.nome_autor, C.nome_cat, L.quantidade_disponivel, L.quantidade_total
        FROM livros L
        INNER JOIN autores A ON A.id_autor = L.id_autor
        INNER JOIN categorias C ON C.id_cat = L.id_cat
        ORDER BY L.titulo";
$livros = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acervo Biblioteca Virtual</title>
    <link rel="stylesheet" href="./css/livros.css">
</head>

<body>

<!-- BACKGROUND RENDER -->
<div class="RENDER BACKGROUND" aria-hidden="true">
<img src="./source/Arthur/home/Group 631.png" alt="">
</div>

<!-- HEADER DO ACERVO -->
<header class="acervo-header">
<div class="acervo-logo">
<a href="principal.php" aria-label="Página inicial">
<img src="./source/image/logo.png" alt="BIBLIOTECH">
</a>
</div>
<h1>Acervo de livros</h1>
<p class="acervo-subtitulo">Explore os livros disponíveis na biblioteca</p>
</header>

<!-- CONTEÚDO PRINCIPAL -->
<main class="acervo-container">

<!-- ÁREA DO BIBLIOTECÁRIO -->
<?php if ($ehBibliotecario): ?>
<div class="acervo-admin">
<a href="cadastro_livro.php" class="btn-cadastrar">Cadastrar novo livro</a>
</div>
<?php endif; ?>

<!-- ÁREA DOS LIVROS -->
<section class="livros-area">
<div class="livros-grid">

<!-- LISTAGEM DOS LIVROS -->
<?php foreach ($livros as $livro): ?>

<!-- CARD DO LIVRO -->
<article
class="livro-card"
data-titulo="<?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>"
data-autor="<?= htmlspecialchars($livro["nome_autor"], ENT_QUOTES, "UTF-8") ?>"
>

<!-- CAPA DO LIVRO -->
<div class="livro-capa">
<div class="livro-capa-fallback">
<?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>
</div>
</div>

<!-- INFORMAÇÕES DO LIVRO -->
<div class="livro-conteudo">
<h2 class="livro-titulo"><?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?></h2>
<p class="livro-autor"><?= htmlspecialchars($livro["nome_autor"], ENT_QUOTES, "UTF-8") ?></p>
<span class="livro-categoria"><?= htmlspecialchars($livro["nome_cat"], ENT_QUOTES, "UTF-8") ?></span>

<!-- DISPONIBILIDADE -->
<div class="livro-disponibilidade">
<?php if ($livro["quantidade_disponivel"] > 0): ?>
<span class="status-disponivel">Disponível</span>
<span class="quantidade-livro">(<?= $livro["quantidade_disponivel"] ?>)</span>
<?php else: ?>
<span class="status-indisponivel">Indisponível</span>
<?php endif; ?>
</div>

<!-- AÇÕES DO LIVRO -->
<div class="livro-acoes">

<?php if ($ehBibliotecario): ?>

<a href="editar_livro.php?id=<?= $livro["id_livro"] ?>" class="btn-editar">Editar</a>

<?php elseif ($podeSolicitar): ?>

<?php if ($livro["quantidade_disponivel"] > 0): ?>

<form method="POST" action="solicitar_emprestimo.php">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
<input type="hidden" name="id_livro" value="<?= $livro["id_livro"] ?>">
<button type="submit" class="btn-solicitar">Solicitar</button>
</form>

<?php else: ?>

<span class="btn-indisponivel">Indisponível</span>

<?php endif; ?>

<?php endif; ?>

</div>
</div>
</article>

<?php endforeach; ?>

</div>
</section>

<!-- BOTÃO VOLTAR -->
<div class="acervo-voltar">
<a href="principal.php">Voltar</a>
</div>

<!--JAVASCRIPT DO LIVROS-->
<script src="./javascript/capas.js"></script>
<script src="./javascript/livros.js"></script>

</main>

</body>