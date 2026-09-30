<?php
// notificacoes.php — tela simples pra ver as notificações da
// pessoa logada (qualquer tipo de usuário pode ter notificação).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
$idUsuario = $_SESSION["id_usuario"];
session_write_close();

// Busca as notificações (guarda quais estavam "não lidas" antes de
// marcar, só pra destacar na tela quais eram novas)
$stmt = $conexao->prepare(
    "SELECT id_notificacao, titulo, mensagem, lida, criada_em
     FROM notificacao WHERE id_usuario = :usuario ORDER BY criada_em DESC"
);
$stmt->execute([":usuario" => $idUsuario]);
$notificacoes = $stmt->fetchAll();

// Já que a pessoa está vendo a tela agora, marca tudo como lido
// automaticamente (não precisa mais clicar uma por uma)
$stmt = $conexao->prepare(
    "UPDATE notificacao SET lida = 1, lida_em = NOW() WHERE id_usuario = :usuario AND lida = 0"
);
$stmt->execute([":usuario" => $idUsuario]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Notificações - Biblioteca Virtual</title>
</head>
<body>
    <h2>Minhas notificações</h2>

    <table border="1" cellpadding="6">
        <tr>
            <th>Título</th>
            <th>Mensagem</th>
            <th>Quando</th>
        </tr>
        <?php foreach ($notificacoes as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["mensagem"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["criada_em"], ENT_QUOTES, "UTF-8") ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($notificacoes)): ?>
            <tr><td colspan="3">Nenhuma notificação.</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
