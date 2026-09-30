<?php
declare(strict_types=1);

/**
 * Classe CurrentUser
 *
 * Descobre quem é o usuário logado. Antes, isso vinha de um
 * cabeçalho (X-User-Id) que qualquer um podia editar e se passar
 * por outra pessoa. Agora vem da sessão do PHP, que só o próprio
 * servidor consegue escrever (o login é quem grava isso).
 */
final class CurrentUser
{
    public static function id(): int
    {
        // Garante que a sessão está ligada (não tem problema chamar
        // de novo se já estiver ligada em outro lugar)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $id = $_SESSION['id_usuario'] ?? null;

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            JsonResponse::error('Você precisa estar logado para acessar isso.', 401, 'authentication_required');
        }

        return (int) $id;
    }

    public static function requireAdmin(PDO $db, int $userId): void
    {
        $statement = $db->prepare('SELECT tipo_usuario, ativo FROM usuario WHERE id_usuario = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        if (!$user || !$user['ativo'] || !in_array($user['tipo_usuario'], ['admin', 'bibliotecario'], true)) {
            JsonResponse::error('Acesso restrito ao administrador ou bibliotecário.', 403, 'forbidden');
        }
    }
}
