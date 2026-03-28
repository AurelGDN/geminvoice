# Changelog - Geminvoice

Toutes les évolutions majeures du module Geminvoice par phase de développement.

## [Alpha 18] - 2026-03-28 (release candidate)

### Sécurité
- **XXE → LIBXML_NONET|LIBXML_NOENT** : `FacturxSource::parseXml()` protégée contre les attaques XML External Entity (SSRF + lecture fichiers serveur). Guard PHP < 8.0 inclus.
- **CSRF** : Remplacement de `$_SESSION['token']` par `currentToken()` (helper Dolibarr officiel) sur toutes les pages d'action (`setup.php`, `index.php`, `review.php`, `mappings.php`). Correction incohérence strict/loose (`!=` vs `!==`).
- **Prompt injection** : `GeminiRecognition::buildPrompt()` — description utilisateur sanitisée (strip caractères de contrôle, mots-clés d'instruction, troncature 250 chars). Suppression de `accounting_code` du prompt : le code comptable est désormais sourcé depuis la BDD (candidat validé par rowid), jamais depuis la réponse IA.
- **XSS vendor name** : `review.php` badge fournisseur — `this.innerHTML` → `this.textContent` pour bloquer l'injection HTML via nom de fournisseur OCR.
- **Scripts CLI sans auth** : `sync_invoices.php` et `migration_alpha6.php` — guard `php_sapi_name()` bloquant tout accès web (HTTP 403). `sync_invoices.php` corrigé aussi pour le path traversal via nom de fichier Drive (`dol_sanitizeFileName()`).
- **Audit composer** : log `dol_syslog()` lors du déclenchement de `composer install` depuis l'admin (login + ID utilisateur tracés).
- **XSS réfléchi** : `$_SERVER["PHP_SELF"]` wrappé dans `dol_escape_htmltag()` sur tous les attributs `action` de formulaire (`setup.php`, `mappings.php`).

### Bugs corrigés
- **`date()` sur string SQL** (`PdpSource`) : `date('Y-m-d', $inv->datef)` → `date('Y-m-d', $this->db->jdate($inv->datef))`. La colonne SQL date était passée directement à `date()`, produisant des dates ~1970.
- **Facture partielle** (`mapper.class.php`) : Ajout d'un `break` dans la boucle `addline()` — en cas d'échec d'ajout de ligne, le traitement s'arrête immédiatement pour éviter la création d'une facture avec des lignes manquantes.
- **SQL UPDATE direct sur `llx_facture_fourn_det`** (`mapper.class.php`) : `enrichExistingInvoice()` utilise désormais `SupplierInvoiceLine::fetch()` + `update()` (Active Record Dolibarr) au lieu d'un SQL brut, garantissant le déclenchement des hooks/triggers.
- **Filtre multi-entity** : Ajout de `AND entity IN (getEntity(...))` sur tous les UPDATE de `staging.class.php`, `linemap.class.php`, `suppliermap.class.php`. Empêche la modification de données appartenant à une autre entité.
- **GDrive retry** : Un fichier Drive n'est plus marqué « processed » en cas d'échec OCR/staging. Il reste dans le dossier source pour permettre une nouvelle tentative au prochain sync.
- **PdpSource atomicité** : `create()` + `update(fk_facture_fourn)` enveloppés dans une même transaction (`begin()`/`commit()`/`rollback()`). Élimine le risque de staging orphelin.

### Optimisation & qualité
- **Cache fournisseurs** (`VendorMatcher`) : `loadSuppliers()` met en cache le résultat dans `$this->suppliers_cache` — une seule requête SQL par instance, même si `findMatch()` est appelé N fois dans un batch.
- **`$user->hasRight()`** : Migration complète de l'ancien style `$user->rights->geminvoice->*` (38 occurrences) vers l'API moderne `$user->hasRight('geminvoice', 'read|write')` sur toutes les pages et classes. Résolution simultanée du bug de double-négation (`empty(...) === false`).
- **Chargement conditionnel produits** (`mappings.php`) : Le catalogue produits n'est plus chargé sur l'onglet "Fournisseurs" où il est inutile.
- **i18n GDrive** : Les 6 messages d'erreur hardcodés en français dans `gdrive.class.php` sont remplacés par des clés `$langs->trans()`. Nouvelles clés ajoutées dans `langs/fr_FR/geminvoice.lang` (`GeminvoiceErrorGDriveJsonInvalid`, `GeminvoiceErrorGDriveJsonMissing`, `GeminvoiceErrorGDriveLibMissing`, `GeminvoiceErrorGDriveListFiles`, `GeminvoiceErrorGDriveDownload`, `GeminvoiceErrorGDriveMarkProcessed`).

---

## [Alpha 18] - 2026-03-27
### Source PDPConnectFR — Intelligence comptable universelle
- **PdpSource** : Nouvelle source `class/sources/PdpSource.class.php` — importe les factures fournisseur brouillon créées par PDPConnectFR pour enrichissement comptable. Découverte via `llx_pdpconnectfr_extlinks`, staging avec `source='pdp'` et `fk_facture_fourn` pré-renseigné.
- **Mapper enrichissement** : Nouvelle méthode `mapper.class.php::enrichExistingInvoice()` — met à jour `fk_code_ventilation` sur les lignes existantes via UPDATE direct (même pattern que le module comptabilité Dolibarr natif). Résolution 3 niveaux : linemap → suggestion → vendor fallback.
- **staging.validate() branché** : Détecte `source='pdp'` et appelle `enrichExistingInvoice()` au lieu de `createSupplierInvoice()`. `fk_facture_fourn` n'est pas écrasé pour les sources PDP.
- **Dashboard** : 4ème carte source PDP dans `index.php` avec bouton de synchronisation manuelle et compteur de factures éligibles. Badge source PDP (rouge, `fa-plug`) dans la liste.
- **review.php** : Champs en lecture seule pour les données PDP (fournisseur, n° facture, montants lignes). Lien direct vers la facture existante. Bouton "Appliquer les codes comptables" au lieu de "Créer la facture". Hidden `_fk_facture_fourn_det` préservé dans le JSON pour le mapping ligne-à-ligne.
- **Admin** : Section "Source PDPConnectFR" dans `admin/setup.php` — constante `GEMINVOICE_PDP_SOURCE_ENABLED`. Visible uniquement si PDPConnectFR est actif.

### Préparation interopérabilité PDP (complété)
- **Anti-doublon PDP :** `staging.class.php::findDuplicate()` enrichie — si PDPConnectFR est actif, vérifie en plus `llx_pdpconnectfr_extlinks` pour détecter les factures déjà importées via le flux PDP (même ref fournisseur via canaux différents).
- **Source `pdp` :** Valeur `pdp` documentée et validée dans le champ `source` de `llx_geminvoice_staging`. Constante `GeminvoiceStaging::SOURCE_PDP` ajoutée aux constantes de source existantes.
- **Documentation frontière modules :** Séparation explicite Geminvoice / PDPConnectFR documentée dans `CLAUDE.md`.

## [Alpha 17] - 2026-03-27
### Matching fournisseur, avoirs & enrichissement tiers
- **Matching fournisseur fuzzy (Axe C) :** Nouvelle classe `class/vendormatcher.class.php` — chaîne exact → normalisé (strip SAS/SARL/…) → substring → `similar_text()`. Seuil 75. `mapper.class.php` utilise ce moteur en priorité avant toute création de tiers.
- **Tiers pré-confirmé (Axe C) :** Badge interactif dans `review.php` — l'opérateur confirme ou rejette le match d'un clic ; le `fk_soc` est persisté dans le JSON staging et transmis à `mapper.class.php` à la validation finale.
- **Avoirs fournisseurs (Axe E) :** Prompt Gemini enrichi — détecte `is_credit_note`, extrait montants positifs. `mapper.class.php` positionne `$inv->type = TYPE_CREDIT_NOTE` (type=2) si avoir. Les lignes sont forcées en valeurs absolues pour les avoirs.
- **Toggle manuel avoir :** Case à cocher dans `review.php` — l'opérateur peut qualifier manuellement un document en avoir avant validation.
- **Enrichissement tiers (Axe B) :** Prompt Gemini extrait désormais `vendor_siret`, `vendor_vat`, `vendor_address`, `vendor_zip`, `vendor_city`. Ces champs sont injectés dans `$soc` lors de la création automatique d'un nouveau tiers.
- **Fix preview (Axe D) :** URL `document.php` corrigée pour les sources `upload`/`facturx` (chemin relatif depuis `DOL_DATA_ROOT` au lieu du basename seul). Fichiers XML : affichage d'un résumé au lieu d'un iframe vide.

## [Alpha 16] - 2026-03-26
### Architecture multi-sources
- **Interface abstraite** : `class/sources/GeminvoiceSourceInterface.php` — contrat commun (`getName`, `getLabel`, `getIcon`, `isConfigured`, `isEnabled`, `fetchAndStage`).
- **GdriveSource** : encapsule le pipeline GDrive → Gemini OCR existant. `cron.class.php` délègue maintenant à cette source.
- **UploadSource** : import PDF/image par lot depuis le navigateur. Validation MIME via `finfo`, staging avec `source='upload'`.
- **FacturxSource** : parse les PDF/A-3 (extraction XML par flux brut + zlib inflate) et les XML autonomes CII (Factur-X, ZUGFeRD) et UBL 2.1 (Peppol). Aucun appel OCR.
- **Colonne `source`** : ajoutée à `llx_geminvoice_staging` (migration SQL dans `init()`). `GeminvoiceStaging` expose `$source` dans `create()`, `fetch()`, `update()`.
- **Dashboard** : 3 panneaux source côte à côte (GDrive, Upload, Factur-X). Badge couleur `Source` dans la liste des factures.
- **Fix** : warning PHP `review.php` — `$cached['rowid']` accédé après `$cached = null` corrigé via `$stale_rowid`.

## [Alpha 15] - 2026-03-26
### Stabilisation & qualité
- **Timeout OCR :** `gemini.class.php` — ajout `CURLOPT_TIMEOUT 60s`. Retour normalisé : toujours `false` en cas d'erreur (plus jamais `null` implicite sur JSON malformé).
- **Budget appels IA :** Compteur par chargement de page dans `review.php`. Constante `GEMINVOICE_RECOGNITION_AI_MAX_CALLS` (défaut 3). Notice Dolibarr si budget atteint. Configurable dans `admin/setup.php`.
- **Cache AI stale :** Pré-vérification du `rowid` du cache `ai_product` contre le catalogue actuel avant usage. Invalidation automatique et re-évaluation si le produit a été supprimé.
- **Lignes vides :** Blocage de `validate_final` si une ligne a qty=0 ET unit_price=0. Message d'erreur précisant le numéro et la description de la ligne.
- **Devise OCR :** Badge d'avertissement orange dans `review.php` si la devise détectée par l'OCR diffère de la devise système Dolibarr.

## [Alpha 14] - 2026-03-26
### Ajouté
- **Reconnaissance IA produit (cascade) :** Nouvelle classe `class/geminirecognition.class.php`. Lorsque le score textmatch est inférieur au seuil configurable, les top-5 candidats sont soumis à Gemini pour arbitrage. Résultat mis en cache dans le JSON staging pour éviter les re-appels.
- **Chaîne de priorités affinée :** P1 🧠 règle mémorisée → P2 🔍 textmatch ≥ seuil → P3 🤖 AI Gemini → P4 🔍 textmatch meilleur effort → P5 📄 JSON OCR → P6 🏢 fallback fournisseur.
- **Badge produit 🤖 XX% :** Indication visuelle de la confiance Gemini sur le sélecteur produit. Indicateur 💾 si résultat depuis le cache.
- **Configuration seuil :** Champ numérique "Seuil de confiance textuelle" (0-100, défaut 80) dans `admin/setup.php`. Constante `GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD`.
- **`findTopCandidates()` :** Nouvelle méthode dans `textmatch.class.php` retournant les N meilleurs candidats sans filtre de seuil, pour alimenter la cascade IA.

## [Alpha 13] - 2026-03-26
### Ajouté
- **Configuration reconnaissance :** Deux constantes `GEMINVOICE_RECOGNITION_TEXTMATCH` (activée par défaut) et `GEMINVOICE_RECOGNITION_AI` (désactivée, expérimental) dans `admin/setup.php`. L'utilisateur choisit ses méthodes de reconnaissance.
- **Auto-mémorisation :** Lors de la validation finale d'une facture, toutes les lignes sont automatiquement mémorisées dans `llx_geminvoice_line_mapping` (description → compte + produit) via upsert.
- **Reconnaissance textuelle locale :** Nouveau moteur `class/textmatch.class.php` — scoring par similarité (substring, token-based avec préfixe commun ≥5 chars, `similar_text()`), normalisation accents/stopwords français, seuil 60%. Intégré dans `review.php` comme priorité 2 (après règles mémorisées, avant IA).
- **Badges produit :** Badges visuels 🧠/🔍/✏️ sur le sélecteur produit dans `review.php` indiquant la source de pré-sélection (règle mémorisée, correspondance textuelle, saisie manuelle).
- **Mappings enrichis :** Colonne "Produit" avec dropdown Select2 dans `mappings.php` pour associer un produit du catalogue aux règles par description.
- **Purge liste :** Bouton "Vider la liste" dans `index.php` pour supprimer en masse les factures en attente et en erreur (avec confirmation).

### Corrigé
- **Bug critique validate_final :** Les instructions `setEventMessages`, `header("Location")` et `exit` étaient incorrectement imbriquées dans le `if ($memorize_vendor)`, empêchant la redirection si la mémorisation fournisseur n'était pas cochée.
- **Variable `$rule` non réinitialisée :** Ajout de `$rule = null;` à chaque itération pour éviter la fuite de valeurs entre lignes.
- **Résultats textmatch écrasés :** Pattern `$text_product_match` temporaire pour éviter que le bloc badge produit n'écrase les résultats de la reconnaissance textuelle.

## [Alpha 12b] - 2026-03-26
### Corrigé
- **Migration SQL :** La migration `alpha12_fk_product.sql` échouait avec une erreur de syntaxe MariaDB (`near 'ALTER TABLE'`) car deux instructions `ALTER TABLE` étaient passées comme une seule chaîne à `$db->query()`. Chaque instruction est désormais une entrée distincte dans le tableau `$sql[]` de `init()`.
- **i18n :** `GeminvoicePlaceholderVat = TVA %` provoquait un `ValueError: Missing format specifier` dans `sprintf()` lors de l'appel à `$langs->trans()`. Corrigé en `TVA %%` (idem `en_US`).
- **Règles par description :** L'ensemble de la fonctionnalité `llx_geminvoice_line_mapping` était cassé (save, fetchAll, findByDescription) car la colonne `fk_product` n'existait pas en base suite à la migration non exécutée.

## [Alpha 12] - 2026-03-25
### Ajouté
- **Multi-langue :** Mise en conformité totale avec le système de traduction Dolibarr (`$langs->trans()`). Audit complet et ajout des clés manquantes dans `fr_FR` et `en_US`.
- **Reconnaissance produit (base) :** Colonne `fk_product` dans `llx_geminvoice_line_mapping` (migration SQL `alpha12_fk_product.sql`). Dropdown produits chargé côté serveur dans `review.php` (même pattern que les comptes comptables). Auto-remplissage TVA + compte comptable au choix d'un produit via attributs `data-tva`/`data-acc`. Endpoint AJAX `ajax/product_search.php` créé (conservé en réserve).
- **UI :** Colonne description élargie (`min-width: 300px`) dans la table des lignes de `review.php`.

## [Alpha 11] - 2026-03-25
### Ajouté
- **Configuration Dynamique :** Appel direct à l'API Google (`models.list`) depuis la page de configuration (`admin/setup.php`) pour lister en temps réel tous les modèles d'IA générative disponibles.
- **Routage OCR :** Le moteur OCR (`class/gemini.class.php`) charge désormais dynamiquement le modèle choisi par l'administrateur dans Dolibarr. Fallback natif sur `gemini-1.5-flash` en l'absence de configuration.

## [Alpha 10] - 2026-03-25
### Ajouté
- **Intelligence :** Ajout de badges visuels (🧠 Mémorisé, 🏢 Facture, 🤖 IA, ✏️ Manuel) à côté des comptes comptables pour indiquer d'où provient la déduction et renforcer la confiance du réviseur.
- **Productivité :** Ajout d'une fonction `✂️ Diviser` permettant de scinder instantanément une ligne en deux. Une alerte demande à l'utilisateur quel pourcentage conserver sur la première ligne, divise le PU HT en fonction, et ajoute la mention "Part X%" aux deux descriptions générées.
- **Sécurité :** Détection automatique des mots-clés d'avoir (remise, rabais, acompte, ristourne) sur `review.php`. Si le montant est positif, un badge rouge d'alerte incite à inverser le signe d'un clic.
- **Fix :** Restauration du sélecteur natif de limite Dolibarr (nombre d'éléments par page) sur `index.php` qui était caché par un argument erroné.

## [Alpha 9] - 2026-03-25
### Ajouté
- **Productivité :** Intégration de Select2 sur les menus déroulants de comptes comptables pour permettre la recherche textuelle dans la page `review.php`.
- **Productivité :** Ajout d'un bouton `⮟` permettant de dupliquer et d'écraser instantanément le compte comptable d'une ligne sur toutes les autres lignes.
- **Ajouté** : Ajout d'un sélecteur explicite de compte comptable au bas de la facture pour choisir ou visualiser précisément le compte qui sera mémorisé comme "Fallback" global pour le fournisseur.

## [Alpha 8] - 2026-03-25
### Ajouté
- Calcul dynamique des totaux en JavaScript sur la page de révision (`review.php`).
- **Nouveau** : Affichage des totaux HT et TTC **par ligne** pour un contrôle précis.
- **Nouveau** : Badge de statut dynamique (OK / ERREUR) pour la cohérence globale.
- **Nouveau** : Amélioration du prompt système de l'Intelligence Artificielle. L'IA extrait désormais intelligemment le champ "Poids" ou "Base" dans la "Qté" si c'est ce multiplicateur qui est utilisé pour le total de ligne (résout le bug des factures d'abattage).
- Pied de tableau (Footer) affichant les sommes calculées HT et TTC des lignes.
- Système d'alerte visuelle (mismatch warning) en cas de différence entre le total des lignes et le total de l'en-tête.
- Mise à jour automatique des totaux lors de l'ajout ou de la suppression de lignes.

## [Alpha 7] - 2026-03-24
### Ajouté
- Pagination standard Dolibarr sur la liste des factures en attente (`index.php`).
- Filtres de recherche (Fichier, Fournisseur, N° Facture) sur la page d'accueil.
- Bouton "Vider les erreurs" pour nettoyer massivement les logs techniques.
- Toggles "Tout cocher / Tout décocher" pour les colonnes Mémoriser et Parafiscale dans `review.php`.
- Coloration sémantique des boutons : Vert pour Valider, Rouge pour Rejeter/Supprimer.

### Corrigé
- Bug d'affichage où toutes les entrées apparaissaient comme des erreurs suite à l'ajout de la pagination.

## [Alpha 6] - 2026-03-24
### Ajouté
- Persistence des erreurs techniques de scan (téléchargement, IA, mapping) en base de données.
- Auto-migration silencieuse du schéma SQL (ajout de la colonne `error_message` dans `llx_geminvoice_staging`).
- Dashboard technique des erreurs sur la page d'accueil pour un meilleur diagnostic.

## [Alpha 5] - 2026-03-23
### Ajouté
- Refonte graphique de `mappings.php` avec alignement précis des colonnes.
- Mode édition directe pour les règles de transformation existantes.
- Pagination et filtres de recherche sur la liste des règles de mapping.

## [Alpha 4] - 2026-03-23
### Ajouté
- Script CLI `scripts/geminvoice_sync.php` pour exécution automatique hors navigateur.
- Intégration native dans le module Cron de Dolibarr (tâches planifiées).
- Documentation de l'automatisation dans la page de configuration du module.

## [Alpha 3] - 2026-03-22
### Ajouté
- Page de révision détaillée `review.php`.
- Gestion des codes comptables par ligne de facture.
- Introduction du badge "Parafiscale" pour les lignes spécifiques.
- Premier système de mémorisation intelligent (apprentissage des libellés répétitifs).

## [Alpha 2] - 2026-03-21
### Ajouté
- Amélioration du connecteur Google Drive (stabilité des téléchargements).
- Optimisation du prompt Gemini pour la reconnaissance des taxes et des fournisseurs complexes.

## [Alpha 1] - 2026-03-20
### Ajouté
- Structure de base du module Dolibarr.
- Pipeline initial : Drive -> Gemini -> Staging -> Calcul Supplier Invoice.
- Table de staging `llx_geminvoice_staging`.
