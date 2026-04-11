=== ML Gallery Migrator Pro ===
Contributors: mlopesdesign
Tags: nextgen gallery, ml gallery, migration, importer, gallery
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.28
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migração robusta do NextGEN Gallery para o ML Gallery Pro. Motor em lotes, logs persistentes, conversão de shortcodes e cópia física de arquivos.

== Description ==

ML Gallery Migrator Pro é a ferramenta definitiva para mover suas coleções do NextGEN Gallery para o ecossistema ML Gallery Pro. Projetado para grandes volumes de dados, o plugin garante integridade física dos arquivos e mapeamento preciso de álbuns e galerias.

Benefícios:
*   **Processamento em Lotes (AJAX)**: Evita timeouts do servidor.
*   **Mapeamento de Álbuns**: Mantém a hierarquia complexa do NextGEN.
*   **Conversão de Shortcodes**: Automatiza a substituição de shortcodes [ngg] por [ml-gallery].
*   **Logs em Tempo Real**: Acompanhe cada etapa da migração.
*   **Segurança**: Verificações de integridade e limpeza completa no desinstalar.

== Installation ==

1.  Envie a pasta `ml-gallery-migrator-pro` para o diretório `/wp-content/plugins/`.
2.  Ative o plugin através do menu 'Plugins' no WordPress.
3.  Acesse 'ML Gallery Migrator' no menu lateral do admin.

== Screenshots ==

1.  Dashboard de Migração - Visão geral e controles de processamento.

== Changelog ==

= 1.0.28 =
* TEST: Release controlada para validação do motor de atualização automática. Sem mudanças no motor de migração.

= 1.0.27 =
* FEATURE: Implementação de atualizador nativo via GitHub Releases.
* CORE: Nova classe `MLGMP\Updater` para detecção e instalação in-place de atualizações.
* FIX: Inclusão de cabeçalhos de suporte a atualizadores externos no arquivo principal.

= 1.0.26 =
* RELEASE: Preparação final para repositório público.
* SECURITY: Reforço em verificações de capability e nonces.
* HYGIENE: Implementação de `uninstall.php` para limpeza completa.
* UI: Refinamentos finais de paridade visual e responsividade.
* DOCS: Melhorias profundas no readme para conformidade com WordPress.org.

= 1.0.25 =
* FIX (Responsive Safety): Refinamentos para narrow admin viewports.
* FIX (Layout Resilience): Handled long translated labels in English and Spanish.
* UI: Graceful stacking for operational panels and hero area.
* i18n: Completed missing translation strings in admin diagnostics.

= 1.0.24 =
* i18n: Implemented native WordPress Internationalization.
* Locales: Added support for Brazilian Portuguese (pt_BR), English (en_US), and Spanish (es_ES).
* Core: Automated locale loading from `/languages` directory.

= 1.0.23 =
* UI: Fixed column height parity for operational panels (Controls and Progress).

= 1.0.22 =
* UI: Migrated operational panel to Full-Width layout for better visual rhythm.

= 1.0.19 =
* FIX (Album Parity): Implemented Two-Pass album migration for high-fidelity mapping.
* FIX (Shortcodes): Correct conversion of encoded quotes (e.g., &quot;) in Nicepage/Gutenberg blocks.
