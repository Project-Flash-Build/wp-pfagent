# WP-PFAgent™

**The open-source AI agent for WordPress automation.** Describe what you want in plain language — *"when a WooCommerce order is over 200 EUR, draft a thank-you note with the customer's history"* — and WP-PFAgent designs the entities, generates the forms and wires the workflows that make it happen, confirming every side-effect before it acts.

WP-PFAgent is the conductor of the Project Flash™ suite. It reads and writes through [WP-PFWorkflow™](https://github.com/Project-Flash-Build/wp-pfworkflow) (the visual workflow engine) and WP-PFManagement™ (the low-code data platform), stopping at a confirmation gate before any change — the agent never takes a side-effect on its own. Bring your own LLM: your keys, your bills, your data stays in your WordPress install.

## ⚠️ Disclaimer — read before downloading

WP-PFAgent™ (PFA) is an AI agent that reads from and acts on the data stored in your system. You download, install and operate it **at your own responsibility**.

**Security warning — prompt injection:** malicious users may plant crafted text inside system records (task names, descriptions, comments or any other stored content) attempting to manipulate the agent's behavior and defeat its safeguards. You are responsible for the security measures of your installation, for reviewing the agent's actions, and for the data you expose to it.

PFA is provided "as is", without warranty of any kind, to the maximum extent permitted by law. See the EULA and the Terms of Service on the product site for details.

## Open source, free

WP-PFAgent is released under the **GNU General Public License v2.0 or later** (see [LICENSE](LICENSE)). It is free to use, read, modify and self-host.

It needs a licensed **WP-PFWorkflow** or **WP-PFManagement** on the WordPress side to do useful work — those are the proprietary, per-customer-licensed plugins of the suite. WP-PFAgent and the open-source [WP-Executor](https://github.com/Project-Flash-Build/wp-executor) runner are free.

## Install

WP-PFAgent is a standard WordPress plugin. Copy the plugin folder into `wp-content/plugins/` (or install the packaged zip through **Plugins → Add New → Upload**), then activate it. Configure your LLM provider and key in the agent's settings.

## Get the suite

WP-PFWorkflow and WP-PFManagement are available for evaluation, purchase and licensing at **[project-flash.com](https://project-flash.com)**.

---

Project Flash™, WP-PFWorkflow™, WP-PFManagement™ and WP-PFAgent™ are trademarks of Project Flash. WP-PFAgent's source code is licensed under GPL-2.0-or-later.
