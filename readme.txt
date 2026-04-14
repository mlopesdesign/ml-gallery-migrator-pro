=== ML Gallery Migrator Pro ===
Contributors: mlopesdesign
Tags: nextgen, migration, gallery, photography, migrate
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.36
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migra o acervo completo do NextGEN Gallery para o ML Gallery Pro. Motor em lotes, logs persistentes, conversão de shortcodes e cópia física de arquivos.

== Description ==

Este plugin é uma ferramenta profissional de migração projetada para mover todo o seu acervo do **NextGEN Gallery** para a estrutura moderna e otimizada do **ML Gallery Pro**.

Diferente de migrações simples, este utilitário realiza uma transferência em dois passos para garantir integridade total:
1. **Migração Física e Estrutural**: Copia arquivos, resolve miniaturas e reconstrói a relação entre Álbuns e Galerias no banco de dados.
2. **Conversão de Conteúdo**: Varre seus posts e páginas em busca de shortcodes NGG antigos, convertendo-os automaticamente para o formato nativo do ML Gallery Pro.

**Destaques:**
* **Motor em Lotes (AJAX)**: Processa grandes bibliotecas (milhares de imagens) sem estourar o limite de memória do servidor.
* **Logs em Tempo Real**: Acompanhe cada arquivo copiado e cada shortcode convertido.
* **Mapeamento Persistente**: Evita duplicidade se você precisar reiniciar a migração.
* **Internacionalização**: Suporte nativo para Português, Inglês e Espanhol.

== Installation ==

1. Certifique-se de que o **NextGEN Gallery** e o **ML Gallery Pro** estão instalados e ativos.
2. Envie a pasta `ml-gallery-migrator-pro` para o diretório `/wp-content/plugins/`.
3. Ative o plugin através do menu 'Plugins' no WordPress.
4. Acesse o menu **ML Migrator NGG** no painel administrativo para iniciar a análise.

== Frequently Asked Questions ==

= O que acontece com meus arquivos originais do NextGEN? =
Eles permanecem intactos. Este plugin realiza uma operação de COPIAR, nunca mover ou excluir seus arquivos originais do NextGEN.

= Posso interromper a migração no meio? =
Sim. O motor em lotes permite pausar ou cancelar a qualquer momento. Devido ao mapeamento de IDs, você pode retomar de onde parou sem duplicar imagens.

= Preciso manter este plugin após a migração? =
Não. Uma vez que todas as suas galerias e shortcodes foram convertidos para o ML Gallery Pro, você pode desativar e excluir este migrador.

== Screenshots ==

1. Dashboard principal com análise do acervo NextGEN.
2. Painel de controles e progresso em tempo real das etapas de migração.
3. Logs detalhados de cada operação realizada pelo motor.

== Changelog ==

= 1.0.36 =
* TEST: Release controlada para validação do motor de atualização automática. Sem mudanças no motor de migração.

= 1.0.27 =

= 1.0.25 =
* FIX (Responsive Safety): Refinamentos para narrowed admin viewports.
* FIX (Layout Resilience): Handled long translated labels in English and Spanish.
* UI: Graceful stacking for operational panels and hero area.
* i18n: Completed missing translation strings in admin diagnostics.

= 1.0.24 =
* WordPress Internationalization (i18n).
* Native support for Brazilian Portuguese (pt_BR), English (en_US), and Spanish (es_ES).
* Automated locale loading from `/languages` directory.

= 1.0.23 =
* FIX (UI Balance): Ajuste na paridade de altura das colunas operacionais (Controles e Progresso).

= 1.0.22 =
* FIX (UI Layout): Migração do painel operacional para largura total (Full-Width).

= 1.0.19 =
* FIX (Album Parity): Implementação de Passagem-Dupla (Two-Pass).
* FIX (Shortcodes): Conversão correta de quotes (ex: &quot;).
