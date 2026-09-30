<?php
declare(strict_types=1);

final class WishlistController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $userId = CurrentUser::id();
        $page = max(1, Request::integer('page', 1) ?? 1);
        $perPage = min(100, max(1, Request::integer('per_page', 20) ?? 20));
        $offset = ($page - 1) * $perPage;
        $sort = ($_GET['sort'] ?? 'newest') === 'title' ? 'L.titulo ASC' : 'D.criado_em DESC';

        $count = $this->db->prepare('SELECT COUNT(*) FROM lista_desejos WHERE id_usuario = :user');
        $count->execute(['user' => $userId]);
        $total = (int) $count->fetchColumn();

        $sql = "SELECT D.id_lista, D.criado_em, L.id_livro, L.titulo, L.ISBN,
                       A.nome_autor AS autor, C.nome_cat AS categoria,
                       L.quantidade_disponivel
                FROM lista_desejos D
                INNER JOIN livros L ON L.id_livro = D.id_livro
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                WHERE D.id_usuario = :user
                ORDER BY {$sort} LIMIT :limit OFFSET :offset";
        $statement = $this->db->prepare($sql);
        $statement->bindValue('user', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        JsonResponse::success($statement->fetchAll(), 200, [
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function store(array $params): never
    {
        $userId = CurrentUser::id();
        $bookId = (int) $params['bookId'];
        $book = $this->db->prepare('SELECT id_livro FROM livros WHERE id_livro = :book');
        $book->execute(['book' => $bookId]);
        if (!$book->fetch()) {
            JsonResponse::error('Livro não encontrado.', 404, 'book_not_found');
        }

        $exists = $this->db->prepare('SELECT id_lista FROM lista_desejos WHERE id_usuario = :user AND id_livro = :book');
        $exists->execute(['user' => $userId, 'book' => $bookId]);
        if ($exists->fetch()) {
            JsonResponse::error('O livro já está na lista de desejos.', 409, 'already_in_wishlist');
        }

        $insert = $this->db->prepare('INSERT INTO lista_desejos (id_usuario, id_livro) VALUES (:user, :book)');
        $insert->execute(['user' => $userId, 'book' => $bookId]);
        JsonResponse::success(['id_lista' => (int) $this->db->lastInsertId()], 201);
    }

    public function destroy(array $params): never
    {
        $statement = $this->db->prepare('DELETE FROM lista_desejos WHERE id_usuario = :user AND id_livro = :book');
        $statement->execute(['user' => CurrentUser::id(), 'book' => (int) $params['bookId']]);
        if ($statement->rowCount() === 0) {
            JsonResponse::error('Livro não encontrado na lista de desejos.', 404, 'wishlist_item_not_found');
        }
        JsonResponse::success(null, 204);
    }
}
