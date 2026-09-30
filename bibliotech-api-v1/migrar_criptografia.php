<?php
// migrar_criptografia.php — roda UMA VEZ, depois de aplicar o
// patch_criptografia.sql (que aumenta o tamanho das colunas). Pega
// os dados pessoais que já existem em texto puro e substitui pela
// versão criptografada.
//
// Só funciona de localhost, e só faz sentido rodar uma vez — se
// rodar 2x, ele criptografaria um dado que já está criptografado,
// e destruiria a informação original. Por isso ele confere um
// "marcador" antes de mexer em cada linha.

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    exit('Só pode rodar de localhost.');
}

require __DIR__ . '/../config/Conexao.php';
require __DIR__ . '/../config/Criptografia.php';

$conexao = Conexao::getConexao();
$totalAtualizado = 0;

// Um jeito simples de saber se um valor já está criptografado: texto
// criptografado é base64 (só letras/números/+/=), CPF puro tem ponto
// e traço, telefone puro tem parênteses, data pura tem formato
// AAAA-MM-DD. Então: se ainda "parece" com o formato original, ainda
// não foi criptografado.
function pareceTextoPuroDeCpfOuTelefone(string $valor): bool
{
    return (bool) preg_match('/[().\-]/', $valor);
}

function pareceDataPura(string $valor): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor);
}

$conexao->beginTransaction();

// ALUNO
$alunos = $conexao->query("SELECT RA, CPF_aluno, dataNascimento, endereco, telefone_aluno FROM aluno")->fetchAll();
foreach ($alunos as $aluno) {
    if (!pareceTextoPuroDeCpfOuTelefone($aluno['CPF_aluno']) && !pareceDataPura($aluno['dataNascimento'])) {
        continue; // já parece criptografado, pula
    }
    $stmt = $conexao->prepare(
        "UPDATE aluno SET CPF_aluno = :cpf, dataNascimento = :nasc, endereco = :end, telefone_aluno = :tel WHERE RA = :ra"
    );
    $stmt->execute([
        ':cpf'  => Criptografia::criptografar($aluno['CPF_aluno']),
        ':nasc' => Criptografia::criptografar($aluno['dataNascimento']),
        ':end'  => Criptografia::criptografar($aluno['endereco']),
        ':tel'  => Criptografia::criptografar($aluno['telefone_aluno']),
        ':ra'   => $aluno['RA'],
    ]);
    $totalAtualizado++;
}

// PROFESSOR
$professores = $conexao->query("SELECT id_professor, telefone_prof FROM professor")->fetchAll();
foreach ($professores as $professor) {
    if (!pareceTextoPuroDeCpfOuTelefone($professor['telefone_prof'])) {
        continue;
    }
    $stmt = $conexao->prepare("UPDATE professor SET telefone_prof = :tel WHERE id_professor = :id");
    $stmt->execute([
        ':tel' => Criptografia::criptografar($professor['telefone_prof']),
        ':id'  => $professor['id_professor'],
    ]);
    $totalAtualizado++;
}

// BIBLIOTECARIO
$bibliotecarios = $conexao->query("SELECT id_bibliotecario, CPF_biblio, telefone_biblio FROM bibliotecario")->fetchAll();
foreach ($bibliotecarios as $biblio) {
    if (!pareceTextoPuroDeCpfOuTelefone($biblio['CPF_biblio'])) {
        continue;
    }
    $stmt = $conexao->prepare(
        "UPDATE bibliotecario SET CPF_biblio = :cpf, telefone_biblio = :tel WHERE id_bibliotecario = :id"
    );
    $stmt->execute([
        ':cpf' => Criptografia::criptografar($biblio['CPF_biblio']),
        ':tel' => Criptografia::criptografar($biblio['telefone_biblio']),
        ':id'  => $biblio['id_bibliotecario'],
    ]);
    $totalAtualizado++;
}

$conexao->commit();

echo "{$totalAtualizado} registro(s) criptografado(s). Agora apague este arquivo.";
