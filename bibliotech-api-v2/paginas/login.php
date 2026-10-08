<?php
// login.php — tela de login de verdade.
// Usa a classe Usuario (já testada) pra conferir e-mail e senha.

session_start();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../models/Usuario.php";

$mensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $senha = $_POST["senha"] ?? "";

    $resultado = Usuario::autenticar($email, $senha);

    if ($resultado["sucesso"]) {
        // Troca o "número" da sessão por um novo, por segurança
        // (evita que alguém reaproveite uma sessão antiga)
        session_regenerate_id(true);

        $_SESSION["id_usuario"] = $resultado["usuario"]->pegarId();
        $_SESSION["nome"] = $resultado["usuario"]->pegarNome();
        $_SESSION["tipo_usuario"] = $resultado["usuario"]->pegarTipoUsuario();

        // Login deu certo: manda pra página principal
        header("Location: principal.php");
        exit;
    }

    $mensagem = $resultado["mensagem"];
}
?>
<!DOCTYPE html>

<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Bibliotech</title>
    <link rel="stylesheet" href="./css/login.css">
</head>

<body>

    <!-- RENDER BACKGROUND -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="">
    </div>

    <!-- Logo -->
    <div class="LOGO">
        <img src="./source/image/logo.png" alt="">
    </div>

    <!-- Texto Login -->
    <div class="textologin">
        <h1>Faça seu login</h1>
        <p>Entre na sua conta para acessar sua Biblioteca Online</p>    
    </div>

    <!--Forms do Backend-->
    <form method="post" action="login.php">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required>
        
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required>
        
        <button type="submit">Entrar</button>

        <!-- Botão/Link de Cadastro dentro do formulário -->
        <div class="link-cadastro">
            <a href="cadastro.php">Criar conta</a>
        </div>
    </form>

    <p id="resultado">
        <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
    </p>

</body>
</body>
</html>
