<?php
declare(strict_types=1);

final class ReportController
{
    public function __construct(private PDO $db) {}

    private function authorize(): void
    {
        CurrentUser::requireAdmin($this->db, CurrentUser::id());
    }

    public function popularBooks(): never
    {
        $this->authorize();
        $limit = min(100, max(1, Request::integer('limit', 20) ?? 20));
        $sql = 'SELECT L.id_livro, L.titulo, A.nome_autor AS autor,
                       COUNT(DISTINCT D.id_lista) AS total_adicoes,
                       COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos
                FROM livros L
                INNER JOIN autores A ON A.id_autor = L.id_autor
                LEFT JOIN lista_desejos D ON D.id_livro = L.id_livro
                LEFT JOIN emprestimo E ON E.id_livro = L.id_livro
                GROUP BY L.id_livro, L.titulo, A.nome_autor
                ORDER BY total_adicoes DESC, total_emprestimos DESC, L.titulo ASC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        JsonResponse::success($statement->fetchAll());
    }

    public function loans(): never
    {
        $this->authorize();
        $status = $_GET['status'] ?? 'all';
        $conditions = match ($status) {
            'active' => 'E.emp_ativo = 1 AND E.data_dev IS NULL',
            'overdue' => 'E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()',
            'completed' => 'E.emp_ativo = 0 AND E.data_dev IS NOT NULL',
            'reserved' => 'E.reserva = 1',
            'all' => '1 = 1',
            default => JsonResponse::error('Status inválido.', 400, 'invalid_status'),
        };
        $sql = "SELECT E.id_emprestimo, U.id_usuario, U.nome AS usuario, U.tipo_usuario,
                       L.id_livro, L.titulo, E.reserva, E.emp_ativo, E.multa_pendente,
                       E.data_emp, E.data_dev_prevista, E.data_dev,
                       CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()
                            THEN 'atrasado'
                            WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                            ELSE 'concluido' END AS status
                FROM emprestimo E
                INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                INNER JOIN livros L ON L.id_livro = E.id_livro
                WHERE {$conditions} ORDER BY E.data_emp DESC";
        $statement = $this->db->query($sql);
        JsonResponse::success($statement->fetchAll());
    }

    public function activeUsers(): never
    {
        $this->authorize();
        $limit = min(100, max(1, Request::integer('limit', 20) ?? 20));
        $sql = 'SELECT U.id_usuario, U.nome, U.email, U.tipo_usuario,
                       COUNT(E.id_emprestimo) AS total_emprestimos,
                       MAX(E.data_emp) AS ultima_atividade
                FROM usuario U INNER JOIN emprestimo E ON E.id_usuario = U.id_usuario
                GROUP BY U.id_usuario, U.nome, U.email, U.tipo_usuario
                ORDER BY total_emprestimos DESC, ultima_atividade DESC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        JsonResponse::success($statement->fetchAll());
    }
}
