# Geminvoice - Dolibarr OCR Module (Gemini AI & Google Drive)

[Français](#version-française) | [English](#english-version)

---

## Version Française

### Présentation
**Geminvoice** est un module pour [Dolibarr ERP & CRM](https://www.dolibarr.org) permettant d'automatiser la saisie des factures fournisseurs grâce à l'intelligence artificielle **Google Gemini**. Il récupère automatiquement vos documents depuis un dossier **Google Drive**, extrait les données critiques (fournisseur, montants, TVA, lignes) et prépare les factures dans Dolibarr.

### Fonctionnalités Clés
- **Synchronisation Google Drive** : Récupération automatique des PDF et images depuis un dossier partagé.
- **OCR Intelligent (Gemini AI)** : Extraction sémantique des données (plus fiable que l'OCR classique).
- **Matching de Tiers** : Reconnaissance automatique des fournisseurs existants ou création automatique.
- **Support Multi-Taux** : Gestion précise des différents taux de TVA et des lignes de facture.
- **Workflow Sécurisé** : Les factures sont créées en brouillon (pour contrôle) et les fichiers traités sont déplacés dans un dossier "processed" sur Drive.

### Prérequis
- **Dolibarr** v10.0 ou supérieure.
- **PHP** 7.4 ou supérieur avec extension cURL.
- Un compte **Google Cloud Console** avec les API suivantes activées :
  - Google Drive API
  - Generative Language API (Gemini)
- Un **Compte de Service Google** (JSON) pour l'accès au Drive.

### Installation
1. Téléchargez le contenu du dépôt dans le dossier `custom/geminvoice` de votre installation Dolibarr.
2. Assurez-vous d'installer les dépendances via composer (le dossier `vendor/` doit exister) ou utilisez une archive complète.
3. Activez le module dans **Configuration > Modules/Applications**.
4. Configurez le module dans la page d'administration dédiée :
   - Clé API Gemini.
   - ID du dossier Google Drive.
   - Contenu du JSON d'authentification Google Service Account.

---

## English Version

### Overview
**Geminvoice** is a module for [Dolibarr ERP & CRM](https://www.dolibarr.org) designed to automate supplier invoice entry using **Google Gemini** artificial intelligence. It automatically fetches your documents from a **Google Drive** folder, extracts critical data (vendor, amounts, VAT, lines), and prepares the invoices within Dolibarr.

### Key Features
- **Google Drive Sync**: Automatic retrieval of PDFs and images from a shared folder.
- **Intelligent OCR (Gemini AI)**: Semantic data extraction (more reliable than traditional OCR).
- **Vendor Matching**: Automatic recognition of existing vendors or automatic creation.
- **Multi-Rate Support**: Precise handling of different VAT rates and invoice lines.
- **Secure Workflow**: Invoices are created as drafts (for review), and processed files are moved to a "processed" folder on Drive.

### Prerequisites
- **Dolibarr** v10.0 or higher.
- **PHP** 7.4 or higher with cURL extension.
- A **Google Cloud Console** account with the following APIs enabled:
  - Google Drive API
  - Generative Language API (Gemini)
- A **Google Service Account** (JSON) for Drive access.

### Installation
1. Download the repository content into the `custom/geminvoice` folder of your Dolibarr installation.
2. Ensure dependencies are installed via composer (the `vendor/` folder must exist) or use a complete archive.
3. Enable the module in **Setup > Modules/Applications**.
4. Configure the module in its dedicated setup page:
   - Gemini API Key.
   - Google Drive Folder ID.
   - Google Service Account JSON content.

---

## À propos de Dolibarr / About Dolibarr
Dolibarr ERP & CRM est un logiciel moderne et facile à utiliser pour gérer votre activité (contacts, factures, commandes, stocks, agenda, etc...).
Dolibarr ERP & CRM is a modern and easy-to-use software package to manage your business (contacts, invoices, orders, stocks, agenda, etc...).

Official Website: [https://www.dolibarr.org](https://www.dolibarr.org)

---
*Developed with ❤️ for the Dolibarr Community.*
