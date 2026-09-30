# Guia do Back-end — Bibliotech (Biblioteca Virtual)

Este documento descreve **tudo** que o back-end já entrega, pra quem for
construir o front-end (pessoa ou IA) entender exatamente o que já existe,
como usar, e o que ainda precisa ser decidido. Não presuma nada que não
esteja escrito aqui — se alguma informação estiver faltando, pergunte
antes de inventar um comportamento.

## 1. Visão geral

- Linguagem: **PHP** (orientado a objetos), banco **MySQL** (nome: `biblioteca`)
- Servido pelo **Apache** (XAMPP), dentro da pasta `bibliotech-api/`
- Duas formas de acessar o sistema:
  1. **Páginas HTML prontas** (pasta `paginas/`) — funcionam sozinhas, sem
     JavaScript, servem de referência/fallback
  2. **API em JSON** (rotas `/api/...`) — pensada pra quem for montar telas
     customizadas em JavaScript (ex: um carrossel de livros)
- **As duas formas usam a mesma autenticação por sessão** (ver seção 2).
  Não existe autenticação por token/JWT — é sessão de navegador (cookie).

## 2. Autenticação

- Login é feito em `paginas/login.php` (formulário e-mail + senha)
- Quando o login dá certo, o servidor cria uma **sessão** (cookie no
  navegador). A partir daí, tanto as páginas quanto a API sabem quem
  está logado através dessa sessão — **não existe outro jeito de se
  autenticar** (não tem cabeçalho tipo `Authorization` ou `X-User-Id`)
- Isso significa: se o front-end for uma aplicação separada (React, Vue,
  etc.) rodando no MESMO domínio/pasta, os `fetch()` para `/api/...`
  funcionam automaticamente (o navegador manda o cookie sozinho). Se for
  rodar em outro domínio/porta, vai precisar de `credentials: "include"`
  nos `fetch()`, e o back-end pode precisar de ajuste de CORS (hoje o
  `Access-Control-Allow-Origin` está como `*`, o que não é compatível
  com cookies entre domínios — **avisar o back-end se isso for necessário**)
- Tipos de usuário existentes: `aluno`, `professor`, `bibliotecario`, `admin`
  (esse último existe no banco mas não tem uso definido ainda)
- Sessão guarda: `$_SESSION["id_usuario"]`, `$_SESSION["nome"]`,
  `$_SESSION["tipo_usuario"]`

## 3. Proteção CSRF (importante pra quem for reaproveitar os formulários)

Toda ação que muda dado (aprovar, recusar, cadastrar, editar, remover,
solicitar) exige um campo `csrf_token` no `POST`, com o valor batendo
com o da sessão atual. Se o front-end for consumir só a API JSON (seção
5), a API **não** exige esse token (ela usa a sessão pra tudo). Esse
token só é exigido nos formulários das páginas HTML (seção 4).

## 4. Páginas HTML prontas (pasta `paginas/`)

Todas exigem estar logado (redirecionam pra `login.php` se não estiver).
Body HTML sem estilo — servem de referência de comportamento, não de
visual.

| Arquivo | Quem pode acessar | O que faz |
|---|---|---|
| `login.php` | Todos (não logado) | Formulário de login (tem link "Criar conta") |
| `cadastro.php` | Todos (não logado) | Cria conta nova (aluno/professor/bibliotecário), com os campos de cada tipo (RA/turma, matrícula, CPF...). Loga automaticamente após criar |
| `logout.php` | Logado | Encerra a sessão |
| `principal.php` | Logado | Tela inicial, muda conteúdo por tipo de usuário |
| `livros.php` | Logado | Lista o acervo. Bibliotecário vê link "Editar"; aluno/professor vê botão "Solicitar" |
| `meus_emprestimos.php` | Logado (aluno/professor) | Histórico dos próprios empréstimos (pendente, ativo, atrasado, concluído), com valor de multa em R$ e botão "Renovar" quando aplicável |
| `cadastro_livro.php` | Só `bibliotecario`/`admin` | Cadastra livro novo (autor/categoria com opção de criar novo) |
| `editar_livro.php?id=X` | Só `bibliotecario`/`admin` | Edita um livro (título, ISBN, editora, ano, quantidade total) |
| `solicitar_emprestimo.php` | Só `aluno`/`professor` | Recebe o POST do botão "Solicitar" da `livros.php` |
| `aprovacoes.php` | Só `bibliotecario`/`admin` | Lista solicitações pendentes, aprova ou recusa |
| `devolucoes.php` | Só `bibliotecario`/`admin` | Lista empréstimos ativos, registra devolução |
| `multas.php` | Só `bibliotecario`/`admin` | Lista multas pendentes (com valor em R$), marca como paga |
| `lista_desejos.php` | Logado (qualquer tipo) | Ver/adicionar/remover livros da lista de desejos. Se o livro estiver sem cópia disponível, também entra na fila de espera (mostra a posição) |
| `notificacoes.php` | Logado (qualquer tipo) | Lista notificações; marca tudo como lido automaticamente ao abrir |
| `relatorio.php?relatorio=X` | Só `bibliotecario`/`admin` | Relatórios (`populares`, `emprestimos`, `usuarios`) |

## 5. API em JSON (rotas `/api/...`)

Todas exigem sessão ativa (senão devolvem `401` com
`{"error":{"code":"authentication_required", ...}}`). Não usam
`csrf_token` (autenticação é só a sessão).

| Método | Rota | O que faz |
|---|---|---|
| GET | `/api/books` | Lista o acervo completo (título, autor, categoria, ISBN, ano, editora, quantidades) |
| GET | `/api/my-loans` | Histórico de empréstimos da pessoa logada, com campo `status` já calculado (`aguardando_aprovacao`, `ativo`, `atrasado`, `concluido`) |
| GET | `/api/wishlist?page=1&per_page=20&sort=newest\|title` | Lista de desejos da pessoa logada |
| POST | `/api/wishlist/{bookId}` | Adiciona um livro à lista de desejos |
| DELETE | `/api/wishlist/{bookId}` | Remove um livro da lista de desejos |
| GET | `/api/notifications?unread=1` | Lista notificações (1 = só não lidas, 0 = todas) |
| PUT | `/api/notifications/{id}/read` | Marca uma notificação como lida |
| PUT | `/api/notifications/read-all` | Marca todas como lidas |
| GET | `/api/reports/popular-books?limit=20` | Só `bibliotecario`/`admin`. Livros mais emprestados |
| GET | `/api/reports/loans?status=all\|active\|overdue\|completed\|reserved` | Só `bibliotecario`/`admin`. Lista de empréstimos |
| GET | `/api/reports/active-users?limit=20` | Só `bibliotecario`/`admin`. Usuários mais ativos |

**Faltando na API (só existe como página HTML hoje)**: solicitar
empréstimo, aprovar/recusar solicitação, registrar devolução, quitar
multa, cadastrar/editar livro. Se o front-end precisar dessas ações via
JSON (não só via formulário HTML), **isso precisa ser pedido ao
back-end** — as rotas ainda não existem.

Todas as respostas de sucesso vêm assim:
```json
{ "data": ... }
```
E erros assim:
```json
{ "error": { "code": "algo_snake_case", "message": "Mensagem em português" } }
```

## 6. Regras de negócio que o front-end precisa saber

- **Prazo de empréstimo**: 15 dias corridos para aluno, 30 para professor
  — contados a partir da **aprovação**, não da solicitação
- **Renovação**: dá pra renovar um empréstimo ativo **uma única vez**,
  ganhando mais um prazo inteiro (15 ou 30 dias) a partir da data atual
  de devolução prevista. Não dá pra renovar se: já estiver atrasado,
  já tiver sido renovado antes, ou tiver alguém esperando aquele livro
  na fila de reserva
- **Limite**: no máximo 3 livros por pessoa, somando os já emprestados
  com os ainda pendentes de aprovação
- **Não dá pra pedir o mesmo livro duas vezes** enquanto já tiver um
  ativo ou pendente dele
- **Matrícula ativa**: aluno com `situacao` diferente de "Ativo"
  (trancado, formado, evadido) não consegue solicitar empréstimo, mesmo
  com a conta de login funcionando normalmente
- **Atraso e multa**: se devolver depois do prazo, o sistema calcula os
  dias de atraso **e o valor em reais** (dias × valor da multa diária,
  configurável na tabela `configuracao`) automaticamente. Enquanto
  tiver multa pendente (ou livro atrasado sem devolver), a pessoa **não
  consegue solicitar** nenhum livro novo, até a bibliotecária quitar a
  multa ou o livro ser devolvido
- **Fila de espera**: ao adicionar na lista de desejos um livro sem
  cópia disponível, a pessoa entra automaticamente na fila daquele
  livro. Quando uma cópia libera (devolução ou recusa de outro pedido),
  a primeira pessoa da fila recebe uma notificação — mas a cópia **não
  fica reservada** pra ela, é só um aviso; quem solicitar primeiro leva
- **Só aluno e professor** pegam livro emprestado. Bibliotecário só
  gerencia (cadastra, aprova, recebe devolução) — nunca aparece como
  "dono" de um empréstimo
- **Dados pessoais criptografados**: CPF, telefone, endereço e data de
  nascimento (de aluno/professor/bibliotecário) ficam criptografados no
  banco. Se o front-end algum dia precisar exibir esses dados de volta,
  vai precisar de uma rota nova no back-end que descriptografe antes de
  devolver — hoje nenhuma tela/rota devolve esses campos
- Estoque (`quantidade_disponivel`) já desconta automaticamente a cópia
  assim que alguém **solicita** (mesmo antes de aprovar) — evita duas
  pessoas disputarem a mesma última cópia

## 7. O que ainda não existe / decisões em aberto

- Tipo `admin` existe no banco, mas nenhuma funcionalidade usa ele de
  forma diferente de `bibliotecario`
- Não existe tela de "esqueci minha senha" (colunas
  `token_redefinicao`/`token_expiracao` existem no banco, mas sem uso)
- Sugestão (não implementada): usar `/api/books` pra montar uma
  exibição visual do acervo (tipo carrossel/roleta), já que ele devolve
  o acervo completo em JSON

## 8. Ambiente de teste (dados de exemplo já no banco)

Todos os usuários de teste têm senha `123456`. Alguns e-mails de
exemplo: `juliazinha1134@gmail.com` (aluno), `roberto.nogueira@escola.edu.br`
(professor), `patricia.lima@escola.edu.br` (bibliotecário).
