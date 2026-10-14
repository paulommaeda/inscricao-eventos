=== Inscrição de Eventos ===
Contributors: paulommaeda
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Eventos com identidade visual própria, lotes e inscrições com cartão ou pagamentos parciais por PIX no checkout K8.

== Installation ==
Instale o ZIP, ative, configure as credenciais K8 Pay e os dados do evento no menu Inscrições.
Use [inscricao_eventos evento="1"] em uma página. A ativação cria o modelo do retiro como rascunho.

== Changelog ==
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
