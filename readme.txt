=== Inscrição de Eventos ===
Contributors: paulommaeda
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Eventos com identidade visual própria, lotes, PIX/cartão online no K8 e cartão físico na maquininha com baixa manual.

== Installation ==
Instale o ZIP, ative, configure as credenciais K8 Pay e os dados do evento no menu Inscrições.
Use [inscricao_eventos evento="1"] em uma página. A ativação cria o modelo do retiro como rascunho.
Use [inscricao_eventos_dashboard evento="1"] para o dashboard privado da organização.

== Changelog ==
= 1.6.1 =
* Conciliação de PIX cuja consulta individual omite referência, pedido e checkout: recupera o vínculo nas consultas autenticadas por referência/listagem e exige o mesmo ID, CPF, meio e valor.
* Preserva o vínculo confirmado entre listagem e consulta individual, sem usar o estado da listagem ou do callback como aprovação.
* Retornos repetidos e estornos reutilizam o ID já conferido; baixas manuais provisórias continuam sendo substituídas sem duplicar crédito.
* Diagnóstico indica a origem do vínculo confirmado e orienta a conferência quando o K8 não informa vínculo em nenhuma consulta.
* Corrige a identidade da cópia publicada Fogo Santo - Origens com paleta antiga, incluindo a substituição da capa antiga pela arte sem QR Code. Datas, lotes e pagamentos são preservados.
* Botão Aplicar capa e cores Origens no editor do evento, removendo a URL que substituía a capa incluída.
* Testes do retorno com campos ausentes, divergências de identidade/ID, repetição, estorno, baixa provisória e visual após atualização do evento existente.

= 1.6.0 =
* Login no próprio dashboard, com retorno direto após entrar, mensagem de erro na página e validação de acesso de administradores/gerentes.
* Detalhes de inscrição em modal, com cadastro, financeiro, pagamentos K8, baixas e histórico sem sair da página.
* Administradores podem editar, registrar/reverter/vincular baixas, sincronizar, conferir/cancelar transações e excluir/restaurar pelo modal; gerentes continuam somente consultando.
* Identidade Origens em azul e preto, com capa sem QR Code e sem datas, lote ou parcelamento fixos. Lote, preço e condições vêm do evento.
* Identidade aplicada ao modelo original do retiro; outros eventos mantêm sua personalização e podem selecionar o novo tema.
* Proteção de login por nonce vinculado ao navegador e redirecionamento local; API privada verifica perfil, nonce, evento e conta de pagamentos.
* Testes de login/retorno, modal, baixas, gerentes, visual móvel e troca dinâmica de lotes, preservando os contratos existentes.

= 1.5.0 =
* Opções independentes por evento para PIX, cartão online e cartão físico na maquininha.
* Cartão físico conclui o cadastro e mostra orientação editável para procurar a secretaria, mantendo o saldo pendente até a baixa manual por administrador.
* Recebimentos da maquininha identificados nos detalhes, filtros, histórico, financeiro e CSV, com crédito manual separado do K8.
* Cartão online desabilitado não emite nem reexibe checkout; tentativas anteriores conservam a conferência e conciliação bancária.
* Eventos somente com cartão físico funcionam sem credenciais K8; consultas e baixas são preservadas se pagamentos online forem habilitados depois.
* Testes de cadastro presencial, bloqueio online, baixas e reversões, permissões de gerentes, saldo, financeiro e interface móvel.

= 1.4.2 =
* Validação do meio de pagamento refeita antes do envio: cartão não depende do campo de entrada PIX, inclusive com seleção restaurada pelo navegador/autopreenchimento.
* Botão Abrir checkout K8 disponível após a criação, com redirecionamento automático e sem nova cobrança.
* Retorno do participante recupera ID_BknBank e vincula a cobrança exata; o ID é apenas uma pista para consulta e confirmação no servidor.
* Conciliação por referência não localizada tenta os filtros dataemissao e checkout, com datas no fuso do evento e diagnóstico de resultados sem dados de terceiros.
* Erros de abertura do checkout identificam o meio e a tentativa, preservando a causa com credenciais/documentos ocultos.
* Falha de atualização no retorno conserva a tela da inscrição; testes específicos de protocolo em produção com API simulada.

= 1.4.1 =
* Ação destacada para dar baixa manual da própria transação K8 quando a sincronização falhar, com painel aberto nas falhas e motivo de indisponibilidade visível.
* ID K8 opcional preservado no histórico e usado como pista para a próxima consulta verificada; nenhuma chamada K8 é necessária para a baixa manual.
* Vínculo de uma baixa externa já registrada à mesma cobrança K8, mantendo valor, data, responsável e saldo, sem novo crédito.
* Resultado de outra transação rejeitada do mesmo checkout não elimina uma baixa vinculada a ID diferente.
* Entrada K8 integralmente baixada manualmente libera pagamentos do saldo restante sem repetir a entrada; baixas parciais e cancelamentos em andamento continuam bloqueando outra cobrança.
* Testes de API indisponível, retorno tardio, vínculo de recebimento existente, reversão, permissões e interface móvel.

= 1.4.0 =
* Baixa manual e reversão exclusivas de administradores, com valor, data, meio, comprovante/motivo e histórico do responsável.
* Recebimentos externos e baixas provisórias vinculadas a tentativas K8, sem transações fictícias nem crédito duplicado no retorno.
* Saldo, dashboard e CSV discriminam créditos K8 e baixas manuais ativas.
* Perfil Gerente de Eventos para consultar eventos, inscrições, financeiro e exportar CSV, sem alterar dados, configurações ou pagamentos.
* Permissões verificadas no servidor e testes de baixa/reversão, conciliação, rollback, acesso e telas no celular.

= 1.3.0 =
* Inscrições iniciadas por PIX podem quitar o saldo restante no cartão habilitado no evento.
* Limite do lote reduz uma parcela a cada mês completo desde a inscrição, até o mínimo de 1x.
* Cálculo no fuso do evento, aniversários mensais, meses curtos e anos bissextos.
* Saldo integral, limite conferido no servidor e condições guardadas em cada nova cobrança.
* Recebimentos por meio efetivamente pago no dashboard, incluindo PIX + cartão na mesma inscrição.
* Testes de parcelamento, pagamento misto, callbacks, estorno, limites e interface móvel.

= 1.2.0 =
* Exclusão permanente de inscrições com pagamentos e de eventos com todos os registros vinculados.
* Confirmação digitada por ID, transação de banco, bloqueios e remoção completa de dados locais.
* Shortcode [inscricao_eventos_dashboard evento="1"] com acesso restrito a administradores.
* Financeiro recebido/a receber, prazos, filtros, lotes, meios, evolução, pendências, paginação e CSV.
* Testes SQL de totais/filtros, rollback de exclusão, controle de acesso e interface desktop/celular.

= 1.1.0 =
* Edição administrativa de inscrições, situação, observações e forma de futuros pagamentos.
* Exclusão para lixeira, restauração e exclusão definitiva de cadastros sem tentativas K8.
* Cancelamento no K8 Pay e estorno de PIX pago pelo WordPress, com confirmação e acompanhamento pela API.
* CPF original preservado em cada cobrança, histórico administrativo e bloqueio de solicitações duplicadas.
* Diagnóstico comparando dados locais e devolvidos pelo K8, com CPF mascarado.
* Migração validada do banco e testes de gestão, cancelamento e interface administrativa.

= 1.0.3 =
* GUIDs K8 com ou sem hífens e com maiúsculas/minúsculas são comparados como o mesmo ID.
* Registros financeiros antigos com IDs compactos são reutilizados sem duplicar crédito.
* Referência não localizada tenta recuperar o ID pelo pedido exato na consulta de transações e confere o pagamento novamente por ID.
* Erros indicam o campo divergente, sem exibir CPF completo; conciliação bem-sucedida limpa a falha anterior.

= 1.0.2 =
* Retornos K8 com ID_BknBank por POST/GET, formulário e JSON; IDs do corpo não substituem o pagamento da URL.
* Identificador recebido preservado para conciliação após falhas temporárias, com erros e data no painel.
* Conferência da transação por pedido, referência ou checkout verificados, mantendo validação de CPF, método e valor.
* Migração automática do banco antes de atender retornos e compatibilidade com checkouts já emitidos.

= 1.0.1 =
* Autenticação OAuth com formulário codificado explicitamente e limpeza de espaços ao copiar credenciais.
* HTTP 400 mostra ambiente e motivo devolvido pelo K8, com chaves e tokens ocultados.
* O botão de teste faz autenticação nova e não reutiliza token em cache.

= 1.0.0 =
* Eventos e lotes reutilizáveis, marca própria e formulário responsivo.
* Checkout K8 Pay v2 com cartão por lote e PIX de valor escolhido.
* Consulta financeira por CPF, histórico e conciliação idempotente.
* Painel administrativo, filtros, CSV, recuperação de tentativas e atualização pelo GitHub.
