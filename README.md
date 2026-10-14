# Inscrição de Eventos — WordPress + K8

Plugin reutilizável para inscrições com lotes, identidade visual própria, cartão no checkout K8 e entrada/complementos por PIX. Código e atualizações em [paulommaeda/inscricao-eventos](https://github.com/paulommaeda/inscricao-eventos), branch `main`.

## Instalação

1. Instale o ZIP em **Plugins → Adicionar plugin → Enviar plugin** e ative.
2. Acesse **Inscrições → Configurações K8**. Cadastre **Client ID, Client Secret e chave AES da API K8 Pay (PagamentosAPI)**. O ambiente inicial é sandbox.
3. Clique em **Testar autenticação K8**. Homologue uma inscrição, o checkout e a confirmação no sandbox antes de usar produção.
4. Em **Eventos e lotes**, edite o modelo do retiro. Preencha a data exata do evento, confira o prazo de quitação e o valor do segundo lote. Publique o evento quando estiver configurado.
5. A ativação cria a página **Inscrições do retiro** e associa o retorno do K8 a ela. Para outros eventos, crie uma página e insira seu shortcode; informe o ID dessa página no cadastro do evento.
6. Para receber dinheiro real, selecione **Produção**, cadastre as credenciais desse ambiente e use HTTPS no site.

Requisitos: WordPress 6.4+, PHP 8.1+, OpenSSL e MySQL/MariaDB com InnoDB. Não depende de WooCommerce nem dos plugins K8 anteriores.

## Modelo inicial do retiro

O modelo nasce como **rascunho**, pois a data exata do evento não foi informada. O título conserva “Carnaval 2028”, e as datas de inscrição conservam o pedido e a imagem. A imagem menciona encerramento uma semana antes de um evento em 2027; confira essa diferença ao publicar.

| Lote | Período inclusive | Valor | Cartão |
| --- | --- | --- | --- |
| Lote 1 | 17/10/2026 a 15/11/2026 | R$ 490,00 | até 10x |
| Lote 2 | 16/11/2026 a 31/12/2026 | R$ 520,00, a confirmar | até 8x |
| Lote 3 | 01/01/2027 a 30/01/2027 | R$ 550,00 | até 6x |

O cartão usa as modalidades à vista e parcelado sem juros do checkout K8. O evento pode aceitar apenas cartão, apenas PIX ou ambos. A entrada mínima do modelo é R$ 1,00, editável; não foi presumida uma entrada obrigatória maior.

## Fluxo do participante

- **Inscrição:** nome, e-mail, telefone com DDD e CPF válido; escolha da forma de pagamento e aceite das condições.
- **Cartão:** checkout K8 pelo valor completo, com o limite de parcelas guardado na inscrição. A aprovação confirma a inscrição inteira; as parcelas seguintes pertencem à relação com o emissor do cartão.
- **PIX:** o participante escolhe a entrada, paga no checkout K8 e acompanha o saldo após a confirmação.
- **Complementos:** em “Minha inscrição”, informa o CPF, consulta o saldo, escolhe quanto pagar naquele momento e recebe um novo checkout exclusivamente PIX.
- O lote, seu preço e o limite de parcelas ficam guardados ao criar a inscrição. Mudanças posteriores não reajustam essa inscrição. Complementos continuam disponíveis depois do encerramento das inscrições, até o prazo de quitação.
- Há uma inscrição por CPF, evento e conta/ambiente K8. O participante consulta um evento por vez.
- A mensagem de quitação aparece no cadastro, no saldo e no rodapé. Use `{data}` para incluir a data limite.

## Shortcodes e marca

```text
[inscricao_eventos evento="1"]
[inscricao_evento id="1"]
[inscricao_eventos]
```

Sem ID, a página permite escolher um evento publicado. Cada evento tem nome, descrição, local, logo, imagem de capa, três cores, política de privacidade e condições de inscrição. O formulário se adapta ao celular e não exige conta WordPress. O checkout mantém a identificação do prestador de pagamento.

Datas incluem todo o dia no fuso configurado no evento, inicialmente `America/Sao_Paulo`. Arquivar impede novas inscrições/pagamentos e mantém o histórico. Excluir um evento é permitido apenas quando não há inscrições.

## Acompanhamento e conciliação

O painel **Inscrições → Acompanhamento** mostra inscritos, valor total, confirmado, saldo e excedentes. Inclui filtros por evento, situação, método e nome/e-mail/CPF, paginação, detalhe da inscrição e exportação CSV.

O endereço do webhook é enviado automaticamente em cada checkout. Cada endereço tem um token próprio. O estado recebido no webhook e o retorno do navegador não fazem baixa: o servidor consulta a API K8 e confere ID da transação, referência, número do pedido, CPF, método e valor antes de registrar crédito.

O histórico usa identificadores únicos de transação e operações com bloqueio/transação de banco. Repetições de callback não duplicam crédito. Estornos confirmados restauram o saldo. Excedentes são registrados e mostrados para conferência, sem descartar pagamentos.

WP-Cron consulta as tentativas a cada cinco minutos, em grupos limitados. A consulta por CPF, o retorno e o botão “Atualizar saldo” também solicitam conciliação. Em sites com pouco tráfego, programe `wp-cron.php` no cron do servidor. O K8 pode confirmar alguns instantes depois do retorno ao site.

Se a criação do checkout sofrer timeout, a tentativa fica **em conferência**. O sistema bloqueia outra cobrança até conferir a anterior. No detalhe administrativo, é possível consultar pela referência/ID, vincular um checkout existente ou liberar uma tentativa depois de conferir no K8 e desativar links antigos. Essas ações administrativas não fazem baixa financeira. Uma transação tardia de tentativa antiga continua sendo conciliada.

## Dados e acesso

A consulta por CPF é o acesso solicitado ao resumo financeiro; CPF é um identificador, e quem o conhece pode consultar esse resumo. Por isso a página pública mostra apenas primeiro nome, lote, total, saldo e pagamentos, omitindo e-mail, telefone e CPF completo. Há limites de tentativas, sessão com cookie HttpOnly/SameSite e proteção CSRF. O cadastro existente não pode ser alterado por essa consulta.

As configurações, CPFs e tokens de API são protegidos por criptografia vinculada às chaves do WordPress. Preserve essas chaves nos backups e nas migrações. As tabelas e inscrições permanecem ao desativar ou remover o plugin. Faça backups do banco para conservar os registros financeiros.

Sandbox e produção são separados por conta/ambiente; mudar as credenciais não atribui pagamentos antigos à conta nova. O painel usa a configuração atual por padrão, com opção para visualizar outros ambientes. Se precisar conciliar uma tentativa antiga, volte às credenciais e ao ambiente correspondentes.

## Atualizações pelo WordPress

A biblioteca **Plugin Update Checker 5.7**, distribuída com sua licença MIT, verifica a branch `main` do repositório público. Não precisa de token GitHub. A instalação inicial é manual; as próximas versões entram no mecanismo de atualização do WordPress. A opção **Instalar automaticamente novas versões do GitHub** está habilitada inicialmente e pode ser desativada nas configurações.

Para novas entregas, aumente a versão no cabeçalho de `inscricao-eventos.php`, em `IE_VERSION` e em `readme.txt`; valide, publique os arquivos em `main` e gere o ZIP. O ZIP contém uma única pasta raiz `inscricao-eventos/`.

## Desenvolvimento e validação

```sh
php tests/financial.php
php tests/authentication.php
npm install
npx playwright install chromium
npm test
python3 scripts/build.py
```

Os testes financeiros usam dublês de WordPress/banco/K8 para verificar valores, CPF, limites de lotes, aprovação, estorno, duplicidade, rollback, proteção de origem e separação de ambientes. Os testes de navegador simulam a API localmente para verificar desktop/celular, cadastro, CPF, pagamentos, retorno, pendências e erros.

Nenhum teste automatizado cria cobrança real. A versão inicial foi validada localmente com respostas simuladas; instalação no seu WordPress, autorização das credenciais, opções efetivas de parcelamento e callback real precisam de homologação na conta K8.

Contrato utilizado: [checkout hospedado K8](https://docs.meuk8.com.br/reference/criatransacaocheckout_criatransacaocheckout_criatransacaocheckout_post), [consulta por referência](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacaoreferencia_consultatransacaoreferencia_post), [callbacks](https://docs.meuk8.com.br/docs/payload-callback) e Swagger oficial `/PagamentosAPI/swagger/docs/v1`, consultado nesta implementação.

## Autenticação recusada / HTTP 400

Na versão 1.0.1, o plugin envia o formulário OAuth explicitamente codificado, conserva símbolos como `+`, `&` e `=` das chaves e limpa espaços/quebras de linha acidentais nas bordas. O teste administrativo consulta novamente a API, mesmo quando existe um token em cache.

O erro agora informa **ambiente**, **API** e o motivo estruturado devolvido pelo K8, sem exibir chaves ou tokens. Se aparecer “As credenciais do usuário não conferem”, confirme Client ID e Client Secret gerados no **K8 Pagamentos**, o ambiente escolhido e a habilitação da API/IP do servidor. Chaves de produção precisam da opção Produção; o padrão inicial do plugin é sandbox. Salve a configuração antes de clicar em testar.

A autenticação foi comparada com a resposta pública do sandbox usando valores fictícios: o endpoint reconhece formulário OAuth e rejeita JSON com `unsupported_grant_type`. Nenhuma credencial real foi usada nessa verificação. A aprovação da sua conta ainda precisa ser testada no WordPress com suas chaves.
