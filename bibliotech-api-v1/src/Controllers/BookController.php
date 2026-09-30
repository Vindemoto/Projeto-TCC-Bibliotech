<?php
declare(strict_types=1);

final class BookController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        // Só libera pra quem está logado, igual as outras rotas
        CurrentUser::id();

        $sql = "SELECT L.id_livro, L.titulo, L.ISBN, L.publicacao, L.editora,
                       A.nome_autor AS autor, C.nome_cat AS categoria,
                       L.quantidade_total, L.quantidade_disponivel
                FROM livros L
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                ORDER BY L.titulo";
        $statement = $this->db->query($sql);

        JsonResponse::success($statement->fetchAll());
    }
}
