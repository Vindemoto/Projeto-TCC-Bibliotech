<?php
declare(strict_types=1);

final class MyLoansController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $userId = CurrentUser::id();

        $sql = "SELECT E.id_emprestimo, L.id_livro, L.titulo, A.nome_autor AS autor,
                       C.nome_cat AS categoria, L.ISBN, L.editora, L.publicacao,
                       E.data_emp, E.data_dev_prevista, E.data_dev, E.emp_ativo,
                       E.reserva, E.multa_pendente, E.dev_pdia, E.valor_multa,
                       CASE
                           WHEN E.reserva = 1 THEN 'aguardando_aprovacao'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'atrasado'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                           ELSE 'concluido'
                       END AS status
                FROM emprestimo E
                INNER JOIN livros L ON L.id_livro = E.id_livro
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                WHERE E.id_usuario = :user
                ORDER BY E.data_emp DESC";
        $statement = $this->db->prepare($sql);
        $statement->bindValue('user', $userId, PDO::PARAM_INT);
        $statement->execute();

        JsonResponse::success($statement->fetchAll());
    }
}
