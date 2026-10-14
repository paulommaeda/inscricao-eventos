# Padrão de desenvolvimento

- Este projeto WordPress deve continuar no GitHub `paulommaeda/inscricao-eventos`, com atualização do WordPress pela branch `main` e biblioteca Plugin Update Checker distribuída no pacote. O usuário adotou GitHub + atualização WordPress como padrão dos seus plugins.
- Ao entregar uma nova versão, aumente o cabeçalho `Version`, `IE_VERSION`, `Stable tag` e a versão do pacote/script de distribuição. Preserve o slug/pasta `inscricao-eventos`.
- Não inclua credenciais ou dados reais de participantes no repositório. O cadastro de credenciais acontece no WordPress.
- O estado do webhook ou do navegador nunca confirma pagamento diretamente. Consulte o K8, confira identidade, CPF, referência, método e valor e mantenha a conciliação idempotente.
- Não gere uma segunda cobrança quando a primeira estiver pendente ou em conferência. Falhas de leitura do saldo devem impedir novas cobranças.
- Preserve o valor e o limite de parcelas guardados na inscrição; editar o lote afeta novas inscrições.
- Ao corrigir CPF de uma inscrição, preserve o CPF criptografado original de cada tentativa K8, preenchendo o snapshot das tentativas antigas antes da edição.
- Inscrições com tentativas K8 devem ser excluídas para a lixeira com histórico preservado. Exclusão definitiva exige ausência de qualquer tentativa de pagamento.
- Cancelamento/estorno exige ação administrativa explícita, identidade conferida na API e confirmação posterior para reverter crédito. Nunca reenvie automaticamente uma solicitação com resultado desconhecido.
- O modelo inicial conserva Carnaval de 2028, inscrições de 17/10/2026 a 30/01/2027 e lote 2 de R$ 520 a confirmar. A data exata do retiro precisa ser configurada pelo usuário; não a deduza da imagem.
- Valide mudanças financeiras com `php tests/financial.php`; mudanças na interface com os testes relevantes de navegador. Não use credenciais reais nesses testes.
- Gere o instalador com `python3 scripts/build.py` e confirme uma única pasta raiz `inscricao-eventos/`.
