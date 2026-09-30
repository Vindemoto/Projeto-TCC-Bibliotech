<?php
// meus_emprestimos.php — mostra o histórico de empréstimos da
// própria pessoa logada (pendentes, ativos, atrasados, concluídos).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$idUsuario = $_SESSION["id_usuario"];
$conexao = Conexao::getConexao();
session_write_close();

$stmt = $conexao->prepare(
    "SELECT L.titulo, A.nome_autor, C.nome_cat, L.ISBN, L.editora, L.publicacao,
            E.data_emp, E.data_dev_prevista, E.data_dev,
            E.emp_ativo, E.reserva, E.multa_pendente, E.dev_pdia
     FROM emprestimo E
     INNER JOIN livros L ON L.id_livro = E.id_livro
     INNER JOIN autores A ON A.id_autor = L.id_autor
     INNER JOIN categorias C ON C.id_cat = L.id_cat
     WHERE E.id_usuario = :id
     ORDER BY E.data_emp DESC"
);
$stmt->execute([":id" => $idUsuario]);
$emprestimos = $stmt->fetchAll();

// Descobre o status de cada empréstimo, em palavras simples
function statusEmprestimo(array $item): string
{
    if ((int) $item["reserva"] === 1) {
        return "Aguardando aprovação";
    }
    if ((int) $item["emp_ativo"] === 1 && $item["data_dev"] === null) {
        return ($item["data_dev_prevista"] < date("Y-m-d")) ? "Atrasado" : "Ativo";
    }
    return "Concluído";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus empréstimos Biblioteca Virtual</title>
    <link rel="stylesheet" href="./css/meus_emprestimos.css">
</head>
<body>

<!-- BACKGROUND RENDER -->
<div class="RENDER BACKGROUND" aria-hidden="true">
<img src="./source/Arthur/home/Group 631.png" alt="">
</div>

<!-- HTML KAUAN SEGUINDO A LÓGICA DO PHP PFVR REVISAR  -->

<!-- Cabeçalho -->
<div class="emprestimos-header">
<h2>Meus empréstimos</h2>
<p>Acompanhe seus livros, prazos e histórico de empréstimos.</p>
</div>

<!-- Carrossel -->
<main class="emprestimos-container">

<!-- Botão anterior -->
<button class="carousel-btn carousel-prev" type="button" aria-label="Livro anterior">
‹
</button>

<!-- Livros -->
<div class="livros-carousel">

<?php foreach ($emprestimos as $item): ?>

<article class="emprestimo-card"
data-titulo="<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>"
data-autor="<?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?>">

<!-- Capa -->
<div class="emprestimo-capa">
<div class="emprestimo-capa-fallback">
<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
</div>
</div>

<!-- Título -->
<div class="emprestimo-card-titulo">
<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
</div>

</article>

<?php endforeach; ?>

</div>

<!-- Botão próximo -->
<button class="carousel-btn carousel-next" type="button" aria-label="Próximo livro">
›
</button>

</main>

<!-- Indicadores -->
<div class="carousel-indicators"></div>

<!-- Informações -->
<?php foreach ($emprestimos as $item): ?>

<section class="emprestimo-detalhes">

<!-- Capa -->
<div class="emprestimo-detalhes-capa">
<div class="emprestimo-capa-fallback">
<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
</div>
</div>

<!-- Dados -->
<div class="emprestimo-informacoes">

<!-- Livro -->
<div class="emprestimo-titulo-area">
<h3>
<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
</h3>

<p class="emprestimo-autor">
<?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?>
</p>

<span class="emprestimo-categoria">
<?= htmlspecialchars($item["nome_cat"], ENT_QUOTES, "UTF-8") ?>
</span>
</div>

<!-- Status -->
<div class="emprestimo-status">
<span class="status-label">Status</span>

<span class="status-valor">
<?= statusEmprestimo($item) ?>
</span>
</div>

<!-- Informações do livro -->
<div class="emprestimo-info-grid">

<div class="info-item">
<span>ISBN</span>
<strong>
<?= htmlspecialchars($item["ISBN"], ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Editora</span>
<strong>
<?= htmlspecialchars($item["editora"], ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Ano</span>
<strong>
<?= htmlspecialchars((string) $item["publicacao"], ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Data do empréstimo</span>
<strong>
<?= htmlspecialchars((string) $item["data_emp"], ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Devolução prevista</span>
<strong>
<?= htmlspecialchars((string) $item["data_dev_prevista"], ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Devolvido em</span>
<strong>
<?= htmlspecialchars((string) ($item["data_dev"] ?? "—"), ENT_QUOTES, "UTF-8") ?>
</strong>
</div>

<div class="info-item">
<span>Dias de atraso</span>
<strong>
<?= (int) $item["dev_pdia"] > 0 ? $item["dev_pdia"] : "—" ?>
</strong>
</div>

<div class="info-item">
<span>Multa pendente</span>
<strong>
<?= $item["multa_pendente"] ? "Sim" : "Não" ?>
</strong>
</div>

</div>

</div>

</section>

<?php endforeach; ?>

<!-- Sem empréstimos -->
<?php if (empty($emprestimos)): ?>

<section class="emprestimos-vazio">

<div class="emprestimos-vazio-icone">📚</div>

<h3>Você ainda não tem nenhum empréstimo.</h3>

<p>Quando você realizar um empréstimo, ele aparecerá aqui.</p>

</section>

<?php endif; ?>

<!-- Voltar -->
<div class="emprestimos-voltar">
<a href="principal.php">Voltar</a>
</div>

<!--JAVASCRIPT DO LIVROS-->
<script src="./javascript/capas.js"></script>
<script src="./javascript/meus_emprestimos.js"></script>

</body>

</html>