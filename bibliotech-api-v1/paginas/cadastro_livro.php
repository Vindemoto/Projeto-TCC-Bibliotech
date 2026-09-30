<?php
// cadastro_livro.php — tela de cadastro de acervo (baseada no
// trabalho do Zaion, adaptada pra bater com as tabelas finais e
// restrita a bibliotecário/admin).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/Csrf.php";

// Só entra quem está logado
if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

// Só bibliotecário (ou admin) pode cadastrar livro
$tipo = $_SESSION["tipo_usuario"];
if ($tipo !== "bibliotecario" && $tipo !== "admin") {
    http_response_code(403);
    exit("Acesso restrito a bibliotecários.");
}

$conexao = Conexao::getConexao();
$mensagem = "";

// Ano mínimo aceito (referência: invenção da imprensa) e máximo
// (o ano atual, calculado sozinho — nunca fica desatualizado)
$anoMinimo = 1450;
$anoMaximo = (int) date("Y");

// Esse array guarda o que a pessoa digitou, pra reaparecer no
// formulário se der erro (em vez de apagar tudo e ter que digitar
// de novo do zero)
$dados = [
    "titulo" => "",
    "id_autor" => "",
    "novo_autor" => "",
    "isbn" => "",
    "editora" => "",
    "publicacao" => "",
    "id_cat" => "",
    "novo_genero" => "",
    "quantidade" => "1",
];

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Guarda tudo que veio do formulário, pra usar tanto na
    // validação quanto pra reexibir se der erro
    $dados["titulo"] = trim($_POST["titulo"] ?? "");
    $dados["id_autor"] = $_POST["id_autor"] ?? "";
    $dados["novo_autor"] = trim($_POST["novo_autor"] ?? "");
    $dados["isbn"] = trim($_POST["isbn"] ?? "");
    $dados["editora"] = trim($_POST["editora"] ?? "");
    $dados["publicacao"] = trim($_POST["publicacao"] ?? "");
    $dados["id_cat"] = $_POST["id_cat"] ?? "";
    $dados["novo_genero"] = trim($_POST["novo_genero"] ?? "");
    $dados["quantidade"] = $_POST["quantidade"] ?? "";

    $titulo = $dados["titulo"];
    $isbn = $dados["isbn"];
    $editora = $dados["editora"];
    $publicacao = $dados["publicacao"];
    $quantidade = (int) $dados["quantidade"];

    // Checa se o ano é um número e está dentro do intervalo aceito
    $anoValido = ctype_digit($publicacao)
        && (int) $publicacao >= $anoMinimo
        && (int) $publicacao <= $anoMaximo;

    if ($titulo === "" || $isbn === "" || $editora === "" || $quantidade < 1) {
        $mensagem = "Título, ISBN, editora e quantidade (mínimo 1) são obrigatórios.";
    } elseif (!$anoValido) {
        $mensagem = "Ano de publicação inválido. Use um valor entre {$anoMinimo} e {$anoMaximo}.";
    } else {
        try {
            // Descobre o id_autor: se escolheu "novo", primeiro confere
            // se já não existe alguém com esse nome (sem diferenciar
            // maiúscula/minúscula), pra não criar autor duplicado
            if ($dados["id_autor"] === "novo") {
                $nomeNovoAutor = $dados["novo_autor"];

                $stmt = $conexao->prepare("SELECT id_autor FROM autores WHERE LOWER(nome_autor) = LOWER(:nome)");
                $stmt->execute([":nome" => $nomeNovoAutor]);
                $existente = $stmt->fetch();

                if ($existente) {
                    $idAutor = (int) $existente["id_autor"];
                } else {
                    $stmt = $conexao->prepare("INSERT INTO autores (nome_autor) VALUES (:nome)");
                    $stmt->execute([":nome" => $nomeNovoAutor]);
                    $idAutor = (int) $conexao->lastInsertId();
                }
            } else {
                $idAutor = (int) $dados["id_autor"];
            }

            // Mesma lógica pra categoria (gênero)
            if ($dados["id_cat"] === "novo") {
                $nomeNovaCategoria = $dados["novo_genero"];

                $stmt = $conexao->prepare("SELECT id_cat FROM categorias WHERE LOWER(nome_cat) = LOWER(:nome)");
                $stmt->execute([":nome" => $nomeNovaCategoria]);
                $existente = $stmt->fetch();

                if ($existente) {
                    $idCategoria = (int) $existente["id_cat"];
                } else {
                    $stmt = $conexao->prepare("INSERT INTO categorias (nome_cat) VALUES (:nome)");
                    $stmt->execute([":nome" => $nomeNovaCategoria]);
                    $idCategoria = (int) $conexao->lastInsertId();
                }
            } else {
                $idCategoria = (int) $dados["id_cat"];
            }

            // Cadastra o livro. quantidade_disponivel começa igual ao
            // quantidade_total (nenhuma cópia emprestada ainda)
            $sql = "INSERT INTO livros
                        (titulo, id_autor, ISBN, publicacao, editora, id_cat, quantidade_total, quantidade_disponivel)
                    VALUES
                        (:titulo, :id_autor, :isbn, :publicacao, :editora, :id_cat, :quantidade, :quantidade)";
            $stmt = $conexao->prepare($sql);
            $stmt->execute([
                ":titulo" => $titulo,
                ":id_autor" => $idAutor,
                ":isbn" => $isbn,
                ":publicacao" => $publicacao,
                ":editora" => $editora,
                ":id_cat" => $idCategoria,
                ":quantidade" => $quantidade,
            ]);
            $idNovoLivro = (int) $conexao->lastInsertId();

            $mensagem = "Livro cadastrado com sucesso!";

            // Não bloqueia, só avisa: já existe outro livro com esse
            // mesmo título (ISBN diferente, já que se fosse igual o
            // cadastro teria dado erro antes de chegar aqui)
            $stmt = $conexao->prepare(
                "SELECT COUNT(*) AS total FROM livros WHERE LOWER(titulo) = LOWER(:titulo) AND id_livro != :id"
            );
            $stmt->execute([":titulo" => $titulo, ":id" => $idNovoLivro]);
            if ((int) $stmt->fetch()["total"] > 0) {
                $mensagem .= " Atenção: já existe outro livro cadastrado com esse mesmo título (ISBN diferente).";
            }

            // Deu certo: limpa o formulário pro próximo cadastro
            $dados = [
                "titulo" => "", "id_autor" => "", "novo_autor" => "",
                "isbn" => "", "editora" => "", "publicacao" => "",
                "id_cat" => "", "novo_genero" => "", "quantidade" => "1",
            ];
        } catch (PDOException $e) {
            // Não mostra o erro técnico do banco pra quem está usando,
            // só uma mensagem genérica (o erro real fica pro log)
            error_log($e->getMessage());
            if ($e->getCode() === "23000" && str_contains($e->getMessage(), "ISBN")) {
                $mensagem = "Já existe um livro cadastrado com esse ISBN.";
            } else {
                $mensagem = "Erro ao cadastrar o livro. Tente novamente.";
            }
        }
    }
}

// Busca autores e categorias já existentes, pra montar as listas
$autores = $conexao->query("SELECT id_autor, nome_autor FROM autores ORDER BY nome_autor")->fetchAll();
$categorias = $conexao->query("SELECT id_cat, nome_cat FROM categorias ORDER BY nome_cat")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Cadastro de Livros - Biblioteca Virtual</title>
</head>
<body>
    <h2>Cadastro de Livros</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
        <label>Título:</label>
        <input type="text" name="titulo" value="<?= htmlspecialchars($dados["titulo"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Autor:</label>
        <select name="id_autor" id="id_autor" onchange="mostrarCampoNovo('autor')">
            <?php foreach ($autores as $autor): ?>
                <option value="<?= $autor["id_autor"] ?>" <?= ((string) $autor["id_autor"] === $dados["id_autor"]) ? "selected" : "" ?>>
                    <?= htmlspecialchars($autor["nome_autor"], ENT_QUOTES, "UTF-8") ?>
                </option>
            <?php endforeach; ?>
            <option value="novo" <?= ($dados["id_autor"] === "novo") ? "selected" : "" ?>>Novo autor...</option>
        </select>
        <input type="text" name="novo_autor" id="novo_autor" placeholder="Nome do novo autor"
               value="<?= htmlspecialchars($dados["novo_autor"], ENT_QUOTES, "UTF-8") ?>"
               style="display: <?= ($dados["id_autor"] === "novo") ? "block" : "none" ?>">

        <label>ISBN:</label>
        <input type="text" name="isbn" value="<?= htmlspecialchars($dados["isbn"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Editora:</label>
        <input type="text" name="editora" value="<?= htmlspecialchars($dados["editora"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Ano de Publicação:</label>
        <input type="number" name="publicacao" min="<?= $anoMinimo ?>" max="<?= $anoMaximo ?>"
               value="<?= htmlspecialchars($dados["publicacao"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Gênero:</label>
        <select name="id_cat" id="id_cat" onchange="mostrarCampoNovo('genero')">
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= $categoria["id_cat"] ?>" <?= ((string) $categoria["id_cat"] === $dados["id_cat"]) ? "selected" : "" ?>>
                    <?= htmlspecialchars($categoria["nome_cat"], ENT_QUOTES, "UTF-8") ?>
                </option>
            <?php endforeach; ?>
            <option value="novo" <?= ($dados["id_cat"] === "novo") ? "selected" : "" ?>>Novo gênero...</option>
        </select>
        <input type="text" name="novo_genero" id="novo_genero" placeholder="Nome do novo gênero"
               value="<?= htmlspecialchars($dados["novo_genero"], ENT_QUOTES, "UTF-8") ?>"
               style="display: <?= ($dados["id_cat"] === "novo") ? "block" : "none" ?>">

        <label>Quantidade:</label>
        <input type="number" name="quantidade" min="1" value="<?= htmlspecialchars($dados["quantidade"], ENT_QUOTES, "UTF-8") ?>">

        <button type="submit">Cadastrar Livro</button>
    </form>

    <p><a href="principal.php">Voltar</a></p>

    <script>
        // Mostra o campo de texto quando a pessoa escolhe "Novo autor..."
        // ou "Novo gênero..." na lista suspensa
        function mostrarCampoNovo(tipo) {
            if (tipo === "autor") {
                const select = document.getElementById("id_autor");
                const campo = document.getElementById("novo_autor");
                campo.style.display = (select.value === "novo") ? "block" : "none";
            } else {
                const select = document.getElementById("id_cat");
                const campo = document.getElementById("novo_genero");
                campo.style.display = (select.value === "novo") ? "block" : "none";
            }
        }
    </script>
</body>
</html>
