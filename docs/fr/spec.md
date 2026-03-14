# Spécification Fonctionnelle & Technique — Composant KYC

**Version :** 2.0.0
**Statut :** Source de vérité
**Domaine :** Fintech / Conformité réglementaire
**Stack :** PHP 8.4+, Symfony 8, Architecture Hexagonale, DDD, Event Sourcing pur
**Décisions d'architecture :** voir `docs/adr/`

---

## Table des matières

1. [Contexte métier](#1-contexte-métier)
2. [Cas d'usage fonctionnels](#2-cas-dusage-fonctionnels)
3. [Modèle du domaine (DDD)](#3-modèle-du-domaine-ddd)
4. [Événements du domaine](#4-événements-du-domaine)
5. [Architecture hexagonale](#5-architecture-hexagonale)
6. [Event Sourcing](#6-event-sourcing)
7. [CQRS — Commandes & Requêtes](#7-cqrs--commandes--requêtes)
8. [Règles métier détaillées](#8-règles-métier-détaillées)
9. [Catalogue des cas d'échec](#9-catalogue-des-cas-déchec)
10. [Sécurité et conformité (RGPD / LCB-FT)](#10-sécurité-et-conformité-rgpd--lcb-ft)
11. [Tests attendus](#11-tests-attendus)
12. [Gouvernance des ADR](#12-gouvernance-des-adr)

---

## 1. Contexte métier

### Problème à résoudre

Un opérateur Fintech doit vérifier l'identité de chaque nouvel utilisateur avant de lui ouvrir un compte ou d'activer un service financier. Cette obligation découle de la directive LCB-FT (Lutte Contre le Blanchiment et le Financement du Terrorisme) et du règlement eIDAS.

Le processus actuel est manuel, lent et non auditable. Ce composant automatise la vérification tout en garantissant une traçabilité complète et inaltérable de chaque décision.

### Valeur produite

| Besoin | Ce que le composant apporte |
|---|---|
| Conformité réglementaire | Audit trail complet et immuable (event sourcing) |
| Réduction du temps de traitement | Pipeline automatisé de bout en bout |
| Traçabilité des décisions | Chaque changement d'état est un événement daté et signé |
| Rejouabilité | L'état peut être reconstruit à n'importe quel point dans le temps |
| Évolutivité | Nouvelles règles métier sans migration de schéma |

### Acteurs

| Acteur | Rôle |
|---|---|
| **Demandeur** | Personne physique soumettant un document d'identité |
| **Système KYC** | Composant analysant et décidant |
| **Compliance Officer** | Peut consulter l'historique complet et forcer une révision manuelle |
| **Système externe** | Consomme les événements de domaine via un bus de messages |

---

## 2. Cas d'usage fonctionnels

### UC-01 — Soumettre une demande KYC

**Acteur :** Demandeur
**Précondition :** L'utilisateur est authentifié dans le système.
**Déclencheur :** L'utilisateur soumet un document d'identité via l'interface.

**Scénario nominal :**
1. Le demandeur soumet un fichier image ou PDF de son document d'identité.
2. Le système crée une demande KYC avec un identifiant unique.
3. Le système contrôle la qualité du fichier (format, taille, netteté, résolution).
4. Le système extrait les données textuelles par OCR.
5. Le système valide les données extraites selon les règles métier.
6. Le système prononce une décision : **approuvé** ou **rejeté**.
7. La décision est enregistrée avec tous les événements ayant conduit à celle-ci.

**Scénarios alternatifs :**
- 3a. Le fichier ne satisfait pas les contrôles qualité → la demande est rejetée à l'étape upload, le demandeur est invité à re-soumettre.
- 4a. L'OCR échoue ou produit un score de confiance insuffisant → la demande passe en état `ocr_failed`, le demandeur est invité à re-soumettre.
- 5a. Les données extraites échouent aux règles métier → la demande est rejetée avec les motifs détaillés.

### UC-02 — Consulter l'historique complet d'une demande

**Acteur :** Compliance Officer
**Précondition :** L'officer dispose du rôle `ROLE_KYC_AUDITOR`.

**Scénario nominal :**
1. L'officer saisit l'identifiant de la demande.
2. Le système retourne la liste ordonnée de tous les événements survenus sur cette demande, avec horodatage et métadonnées.
3. L'officer peut voir l'état de la demande à n'importe quel point dans le temps.

### UC-03 — Déclencher une révision manuelle

**Acteur :** Compliance Officer
**Précondition :** La demande est dans l'état `rejected` ou `ocr_failed`.

**Scénario nominal :**
1. L'officer commande une révision manuelle en fournissant un motif.
2. La demande repasse en état `under_manual_review`.
3. L'officer saisit sa décision (approuver ou rejeter) avec justification.
4. Un événement `ManualReviewDecisionRecorded` est émis et persiste la décision.

### UC-04 — Rejouer les projections

**Acteur :** Système (administration technique)
**Précondition :** Un événement de domaine a été corrigé ou une nouvelle projection a été créée.

**Scénario nominal :**
1. L'administrateur déclenche la reconstruction d'une projection.
2. Le système rejoue tous les événements depuis le début.
3. La projection est reconstruite dans son état final.

---

## 3. Modèle du domaine (DDD)

### 3.1 Agrégat racine : `KycRequest`

`KycRequest` est le seul agrégat de ce domaine bornée. Il encapsule toute la logique métier et garantit ses invariants.

**Identifiant :** `KycRequestId` (UUID v7)

**Cycle de vie métier :**
```
submitted → document_uploaded → ocr_completed → approved
                                              ↘ rejected
                 ↘ document_rejected (qualité)
                              ↘ ocr_failed
                                         ↘ under_manual_review → approved | rejected
```

**Invariants garantis par l'agrégat :**
- Un document ne peut être analysé que s'il a été uploadé avec succès.
- L'OCR ne peut être lancé que si le document est dans l'état `document_uploaded`.
- Une décision finale (`approved` / `rejected`) est irréversible sauf révision manuelle explicite.
- Le demandeur doit être majeur (≥ 18 ans) au moment de la décision.
- Le document ne doit pas être expiré au moment de la décision.

### 3.2 Value Objects

| Value Object | Rôle | Règles d'intégrité |
|---|---|---|
| `KycRequestId` | Identifiant de la demande | UUID v7 valide |
| `ApplicantId` | Identifiant de l'utilisateur demandeur | UUID v4 valide, non nul |
| `DocumentType` | Type de document (CNI, Passeport, Titre de séjour) | Valeur dans une liste fermée |
| `MrzCode` | Code MRZ du document | 2 lignes de 30 ou 44 caractères alphanum |
| `DocumentId` | Numéro du document officiel | Alphanumérique, 9–12 caractères |
| `OcrConfidenceScore` | Score de confiance de l'OCR | Float entre 0 et 100 |
| `BlurVarianceScore` | Score de netteté Laplacien | Float ≥ 0 |
| `ExpiryDate` | Date d'expiration du document | Date future au moment de la validation |
| `BirthDate` | Date de naissance | Date passée, résulte en âge ≥ 18 ans |
| `FailureReason` | Motif de rejet structuré | Code + message non vide |

### 3.3 Entités et agrégats liés

- `KycAuditTrail` — projection en lecture seule de l'historique complet (pas un agrégat, c'est un read model).
- `ManualReview` — entité associée à une révision manuelle, rattachée à `KycRequest`.

---

## 4. Événements du domaine

Chaque action sur l'agrégat produit un événement. L'état de l'agrégat est **exclusivement reconstitué** par rejeu de ces événements.

### 4.1 Catalogue des événements

| Événement | Déclencheur métier | Données portées |
|---|---|---|
| `KycRequestSubmitted` | Demande créée | `kycRequestId`, `applicantId`, `documentType`, `occurredAt` |
| `DocumentUploaded` | Fichier validé et stocké | `kycRequestId`, `storagePath`, `mimeType`, `sizeBytes`, `dpi`, `blurVariance`, `sha256Hash`, `occurredAt` |
| `DocumentRejectedOnUpload` | Fichier refusé à la qualité | `kycRequestId`, `failureReason`, `occurredAt` |
| `OcrExtractionSucceeded` | OCR réussi | `kycRequestId`, `lastName`, `firstName`, `birthDate`, `expiryDate`, `documentId`, `mrz`, `confidenceScore`, `occurredAt` |
| `OcrExtractionFailed` | OCR échoué ou score insuffisant | `kycRequestId`, `failureReason`, `confidenceScore?`, `occurredAt` |
| `KycApproved` | Toutes les validations passées | `kycRequestId`, `occurredAt` |
| `KycRejected` | Au moins une règle métier violée | `kycRequestId`, `failureReasons[]`, `occurredAt` |
| `ManualReviewRequested` | Révision manuelle déclenchée | `kycRequestId`, `requestedBy`, `reason`, `occurredAt` |
| `ManualReviewDecisionRecorded` | Décision humaine saisie | `kycRequestId`, `reviewerId`, `decision`, `justification`, `occurredAt` |

### 4.2 Structure d'un événement

Chaque événement respecte ce contrat :

```
DomainEvent
├── eventId        : UUID v7 (identifiant unique de l'événement)
├── aggregateId    : KycRequestId
├── aggregateType  : 'KycRequest'
├── eventType      : string (nom de la classe de l'événement)
├── payload        : array (données métier sérialisées)
├── occurredAt     : DateTimeImmutable
└── version        : int (numéro de séquence au sein de l'agrégat)
```

---

## 5. Architecture hexagonale

```
┌──────────────────────────────────────────────────────────────────────┐
│                        INFRASTRUCTURE                                │
│                                                                      │
│  ┌──────────────┐   ┌──────────────────┐   ┌──────────────────────┐ │
│  │  HTTP/Symfony│   │  CLI / Console   │   │  Messenger / Queue   │ │
│  │  Controllers │   │  Commands        │   │  Consumers           │ │
│  └──────┬───────┘   └────────┬─────────┘   └──────────┬───────────┘ │
│         │                   │                          │             │
│─────────┼───────────────────┼──────────────────────────┼────────────│
│         │           APPLICATION (Use Cases)            │             │
│         ▼                   ▼                          ▼             │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  Command Handlers          Query Handlers                       │ │
│  │  SubmitKycRequestHandler   GetKycRequestStatusQuery             │ │
│  │  UploadDocumentHandler     GetKycAuditTrailQuery                │ │
│  │  ExtractOcrHandler         ListPendingReviewsQuery              │ │
│  │  ValidateKycHandler                                             │ │
│  │  RequestManualReviewHandler                                     │ │
│  └──────────────────────────┬──────────────────────────────────────┘ │
│                             │                                        │
│─────────────────────────────┼────────────────────────────────────────│
│                        DOMAINE                                       │
│                             ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  KycRequest (Agrégat)    Domain Events    Value Objects         │ │
│  │  Règles métier           Invariants       Specifications        │ │
│  └──────────────────────────┬──────────────────────────────────────┘ │
│                             │                                        │
│─────────────────────────────┼────────────────────────────────────────│
│                         PORTS (interfaces)                           │
│                             │                                        │
│  ┌──────────────┐   ┌───────┴────────┐   ┌────────────────────────┐ │
│  │ EventStore   │   │ DocumentStorage│   │ OcrPort                │ │
│  │ Port         │   │ Port           │   │                        │ │
│  └──────┬───────┘   └───────┬────────┘   └────────────┬───────────┘ │
│         │                   │                          │             │
│─────────┼───────────────────┼──────────────────────────┼────────────│
│         │           ADAPTERS (implémentations)          │             │
│         ▼                   ▼                          ▼             │
│  ┌──────────────┐   ┌───────────────┐   ┌────────────────────────┐  │
│  │ Doctrine     │   │ LocalFilesystem│  │ TesseractOcr           │  │
│  │ EventStore   │   │ Adapter        │  │ Adapter                │  │
│  └──────────────┘   └───────────────┘   └────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.1 Ports définis

**Ports sortants (driven ports) — interfaces dans le domaine :**

| Port | Responsabilité |
|---|---|
| `EventStorePort` | Persiste et charge les événements d'un agrégat |
| `DocumentStoragePort` | Stocke et récupère les fichiers de documents |
| `OcrPort` | Extrait le texte d'un fichier |
| `DomainEventPublisherPort` | Publie les événements vers l'extérieur (bus) |

**Ports entrants (driving ports) — interfaces dans l'application :**

| Port | Responsabilité |
|---|---|
| `CommandBusPort` | Achemine les commandes vers leurs handlers |
| `QueryBusPort` | Achemine les requêtes vers leurs handlers |

---

## 6. Event Sourcing

### 6.1 Principe

L'agrégat `KycRequest` **ne stocke pas son état** dans une table relationnelle. Son état courant est **uniquement reconstitué** en rejouant la séquence ordonnée de ses événements depuis l'event store.

```
EventStore.load(kycRequestId)
  → [KycRequestSubmitted, DocumentUploaded, OcrExtractionSucceeded, KycApproved]
  → KycRequest.apply(KycRequestSubmitted) → état initial
  → KycRequest.apply(DocumentUploaded)     → document présent
  → KycRequest.apply(OcrExtractionSucceeded) → données OCR disponibles
  → KycRequest.apply(KycApproved)          → état final : approved
```

### 6.2 Port de l'Event Store

```
EventStorePort
├── append(aggregateId, events[], expectedVersion) : void
│     → Lève OptimisticConcurrencyException si version incorrecte
├── load(aggregateId) : DomainEvent[]
│     → Retourne les événements dans l'ordre (version ASC)
└── loadFrom(aggregateId, fromVersion) : DomainEvent[]
      → Pour les snapshots (décision ADR à venir)
```

### 6.3 Reconstitution de l'agrégat

Le `KycRequestRepository` (couche application) orchestre la reconstitution :

1. Chargement des événements depuis l'`EventStorePort`.
2. Création d'un agrégat vide `KycRequest::reconstitute(events[])`.
3. Application séquentielle de chaque événement via `apply(event)`.
4. Retour de l'agrégat dans son état courant.

### 6.4 Projections (Read Models)

Les projections sont des vues dénormalisées construites en écoutant les événements. Elles sont **détruites et reconstruites à volonté** (rejoué depuis l'event store).

| Projection | Contenu | Usage |
|---|---|---|
| `KycRequestStatusView` | `kycRequestId`, `status`, `applicantId`, `updatedAt` | Affichage de l'état courant |
| `KycAuditTrailView` | Liste de tous les événements avec payload | Audit compliance |
| `PendingManualReviewView` | Demandes en attente de révision humaine | Interface backoffice |
| `KycDecisionReportView` | Statistiques des décisions par période | Reporting |

---

## 7. CQRS — Commandes & Requêtes

### 7.1 Commandes (écriture)

| Commande | Handler | Événements produits |
|---|---|---|
| `SubmitKycRequest` | `SubmitKycRequestHandler` | `KycRequestSubmitted` |
| `UploadDocument` | `UploadDocumentHandler` | `DocumentUploaded` ou `DocumentRejectedOnUpload` |
| `ExtractOcr` | `ExtractOcrHandler` | `OcrExtractionSucceeded` ou `OcrExtractionFailed` |
| `ValidateKyc` | `ValidateKycHandler` | `KycApproved` ou `KycRejected` |
| `RequestManualReview` | `RequestManualReviewHandler` | `ManualReviewRequested` |
| `RecordManualReviewDecision` | `RecordManualReviewDecisionHandler` | `ManualReviewDecisionRecorded` + `KycApproved` ou `KycRejected` |

### 7.2 Flux d'une commande

```
HTTP POST /kyc/{id}/upload
    │
    ▼
UploadDocumentController
    │ dispatch(UploadDocument command)
    ▼
CommandBus → UploadDocumentHandler
    │
    ├─ DocumentStoragePort.store(file) → storagePath
    ├─ OcrPort (appelé plus tard par ExtractOcrHandler)
    ├─ KycRequest.uploadDocument(storagePath, metadata) → [DocumentUploaded]
    └─ EventStorePort.append(kycRequestId, [DocumentUploaded], expectedVersion)
         └─ DomainEventPublisherPort.publish([DocumentUploaded])
```

### 7.3 Requêtes (lecture)

| Requête | Description | Source |
|---|---|---|
| `GetKycRequestStatus` | État courant d'une demande | `KycRequestStatusView` |
| `GetKycAuditTrail` | Historique complet des événements | `KycAuditTrailView` |
| `ListPendingManualReviews` | Demandes en attente de révision | `PendingManualReviewView` |
| `GetKycDecisionReport` | Rapport de décisions sur une période | `KycDecisionReportView` |

---

## 8. Règles métier détaillées

### 8.1 Contrôles qualité du document (étape Upload)

| Règle | Valeur seuil | Événement si échec |
|---|---|---|
| Taille maximale | 10 Mo | `DocumentRejectedOnUpload` |
| Formats acceptés | `image/jpeg`, `image/png`, `application/pdf` | `DocumentRejectedOnUpload` |
| Résolution minimale | 300 DPI (métadonnées EXIF) | `DocumentRejectedOnUpload` |
| Score de netteté (Laplacien) | Variance ≥ 100 | `DocumentRejectedOnUpload` |
| Nombre de pages (PDF) | ≤ 2 pages | `DocumentRejectedOnUpload` |

Chaque rejet produit un `FailureReason` structuré portant le code d'erreur et le message destiné à l'utilisateur.

### 8.2 Extraction OCR (étape OCR)

| Paramètre | Valeur | Justification |
|---|---|---|
| Langue | `fra+eng` | Couverture bilingue des documents français |
| Moteur | LSTM (oem=1) | Meilleure précision sur les documents modernes |
| Mise en page | Auto (psm=3) | Adapté aux documents structurés |
| Prétraitement | Binarisation + deskew | Améliore le taux de reconnaissance |
| Timeout | 30 secondes | Prévient les blocages |
| Score de confiance minimal | 60 % | En dessous, les données extraites sont non fiables |

Champs extraits et portés par `OcrExtractionSucceeded` :

| Champ | Description |
|---|---|
| `lastName` | Nom de famille extrait |
| `firstName` | Prénom extrait |
| `birthDate` | Date de naissance (ISO 8601) |
| `expiryDate` | Date d'expiration du document (ISO 8601) |
| `documentId` | Numéro officiel du document |
| `mrz` | Code MRZ complet (2 lignes) |
| `confidenceScore` | Score de confiance moyen Tesseract |

### 8.3 Validation métier (étape Validation)

Les règles suivantes sont appliquées sur les données portées par `OcrExtractionSucceeded` :

| Champ | Règle métier | Motif de rejet |
|---|---|---|
| `lastName` | Présent, lettres + tirets, 2–50 caractères | `INVALID_LAST_NAME` |
| `firstName` | Présent, lettres + tirets, 2–50 caractères | `INVALID_FIRST_NAME` |
| `birthDate` | Date passée, âge du demandeur ≥ 18 ans aujourd'hui | `UNDERAGE_APPLICANT` |
| `expiryDate` | Date strictement future | `DOCUMENT_EXPIRED` |
| `documentId` | Alphanumérique, 9–12 caractères | `INVALID_DOCUMENT_ID` |
| `mrz` | 2 lignes de 30 ou 44 caractères (TD1 / TD3) | `INVALID_MRZ` |

**Règle de cumul :** toutes les violations sont collectées avant de prononcer le rejet. L'événement `KycRejected` porte le tableau complet des `FailureReason`.

**Exceptions bloquantes immédiates** (court-circuitent la collecte) :
- `UNDERAGE_APPLICANT` : violation réglementaire, arrêt immédiat.
- `DOCUMENT_EXPIRED` : arrêt immédiat, le demandeur doit fournir un nouveau document.

### 8.4 Révision manuelle

- Seul un `ROLE_KYC_REVIEWER` peut déclencher et conclure une révision manuelle.
- Une révision manuelle ne peut être déclenchée que si la demande est dans l'état `rejected` ou `ocr_failed`.
- La justification de la décision humaine est obligatoire (non vide, ≥ 20 caractères).
- La décision manuelle est finale et ne peut pas être annulée (nouveau cycle de demande requis).

---

## 9. Catalogue des cas d'échec

| Code | Étape | Cause | Événement produit | État final |
|---|---|---|---|---|
| `E_UPLOAD_SIZE` | Upload | Fichier > 10 Mo | `DocumentRejectedOnUpload` | `document_rejected` |
| `E_UPLOAD_MIME` | Upload | Type MIME non autorisé | `DocumentRejectedOnUpload` | `document_rejected` |
| `E_UPLOAD_BLUR` | Upload | Variance Laplacien < 100 | `DocumentRejectedOnUpload` | `document_rejected` |
| `E_UPLOAD_DPI` | Upload | Résolution < 300 DPI | `DocumentRejectedOnUpload` | `document_rejected` |
| `E_UPLOAD_PAGES` | Upload | PDF > 2 pages | `DocumentRejectedOnUpload` | `document_rejected` |
| `E_OCR_TIMEOUT` | OCR | Tesseract dépasse 30s | `OcrExtractionFailed` | `ocr_failed` |
| `E_OCR_CONFIDENCE` | OCR | Score confiance < 60 % | `OcrExtractionFailed` | `ocr_failed` |
| `E_OCR_CORRUPT` | OCR | Fichier illisible | `OcrExtractionFailed` | `ocr_failed` |
| `E_VAL_NAME` | Validation | Nom ou prénom invalide | dans `KycRejected.failureReasons` | `rejected` |
| `E_VAL_EXPIRED` | Validation | Document expiré | dans `KycRejected.failureReasons` | `rejected` |
| `E_VAL_UNDERAGE` | Validation | Demandeur mineur | dans `KycRejected.failureReasons` | `rejected` |
| `E_VAL_MRZ` | Validation | MRZ invalide | dans `KycRejected.failureReasons` | `rejected` |
| `E_VAL_DOC_ID` | Validation | Numéro de document invalide | dans `KycRejected.failureReasons` | `rejected` |

**Messages utilisateur recommandés :**

- `E_UPLOAD_BLUR` : *"L'image est trop floue. Prenez la photo dans un endroit bien éclairé en vous assurant que le texte est net."*
- `E_VAL_EXPIRED` : *"Votre document est expiré depuis le {date}. Veuillez fournir un document en cours de validité."*
- `E_VAL_UNDERAGE` : *"Vous devez avoir au moins 18 ans pour effectuer cette vérification."*
- `E_OCR_CONFIDENCE` : *"Nous n'avons pas pu lire votre document. Vérifiez qu'il n'y a pas de reflet et que le document est à plat."*

---

## 10. Sécurité et conformité (RGPD / LCB-FT)

### 10.1 Stockage des documents

- Les fichiers sont stockés **hors de la racine publique**.
- Chaque fichier est nommé `{kycRequestId}_{timestamp}_{uuid}.{ext}` — jamais avec le nom original.
- Permissions filesystem : `0640`.
- Un hash SHA-256 du fichier est porté par l'événement `DocumentUploaded` pour détecter toute altération ultérieure.
- Aucune URL directe : l'accès passe par un contrôleur authentifié qui vérifie les droits avant de streamer.

### 10.2 Durée de rétention

| Donnée | Durée | Base légale |
|---|---|---|
| Fichier document brut | 30 jours après décision finale | Minimisation RGPD |
| Données extraites (nom, DOB) | 5 ans | LCB-FT art. L561-12 |
| Événements du domaine (audit trail) | 5 ans | Obligation de traçabilité LCB-FT |

La suppression des fichiers bruts est déclenchée par une commande planifiée. Elle émet un événement `DocumentPurged` afin que l'audit trail reste cohérent.

### 10.3 Journalisation

- Aucune donnée personnelle (nom, date de naissance, numéro de document) n'apparaît dans les logs applicatifs.
- Seuls les identifiants techniques (`kycRequestId`, événement, code d'erreur) sont loggés.
- Les événements de domaine constituent l'audit trail réglementaire — ils ne sont pas des logs.

### 10.4 Concurrence et intégrité

L'event store applique un **verrou optimiste** basé sur le numéro de version de l'agrégat. Toute tentative de persister sur une version déjà écrite lève une `OptimisticConcurrencyException`. Cela garantit l'intégrité de la séquence d'événements.

---

## 11. Tests attendus

### 11.1 Tests du domaine (unitaires purs, zéro infrastructure)

| Scénario | Assertion |
|---|---|
| Soumission d'une demande valide | `KycRequestSubmitted` produit avec les bonnes données |
| Upload sur une demande non soumise | Exception de domaine levée (invariant) |
| OCR réussi après upload valide | `OcrExtractionSucceeded` produit |
| Validation d'un demandeur mineur | `KycRejected` avec code `E_VAL_UNDERAGE` |
| Validation d'un document expiré | `KycRejected` avec code `E_VAL_EXPIRED` |
| Approbation avec toutes les règles satisfaites | `KycApproved` produit |
| Reconstitution depuis la séquence d'événements | État final identique à l'état construit en direct |
| Tentative de transition interdite | Exception de domaine levée |

### 11.2 Tests d'intégration (avec adapters réels ou in-memory)

| Scénario | État final attendu |
|---|---|
| Pipeline complet — données valides | `approved` — 4 événements en store |
| Pipeline complet — document expiré | `rejected` — événements dont `KycRejected` |
| Pipeline complet — OCR confiance faible | `ocr_failed` |
| Image floue soumise | `document_rejected` |
| Révision manuelle → approbation | `approved` — `ManualReviewDecisionRecorded` + `KycApproved` |
| Concurrence sur même agrégat | `OptimisticConcurrencyException` |
| Reconstruction de projection depuis event store | Projection identique à l'état attendu |

### 11.3 Tests de contrat des adapters

Chaque adapter implémentant un port doit passer une suite de tests de conformité (contrat du port) :
- `EventStorePort` : append, load, version optimiste.
- `DocumentStoragePort` : store, retrieve, delete.
- `OcrPort` : extraction réussie, timeout, confiance insuffisante.

---

## 12. Gouvernance des ADR

Les décisions d'architecture sont documentées sous forme d'ADR (Architecture Decision Records) dans `docs/adr/`.

### Format d'un ADR

```
# ADR-NNN — Titre court

**Statut :** Proposé | Accepté | Supersedé par ADR-XXX
**Date :** YYYY-MM-DD

## Contexte
Pourquoi cette décision est nécessaire.

## Options considérées
1. Option A
2. Option B

## Décision
Option retenue et justification.

## Conséquences
Ce que cette décision implique (positif et négatif).
```

### ADR à rédiger (backlog)

| ID | Sujet |
|---|---|
| ADR-001 | Choix de l'implémentation de l'Event Store (Doctrine vs EventStoreDB vs custom) |
| ADR-002 | Stratégie de snapshot (seuil de version, format) |
| ADR-003 | Transport des événements de domaine vers l'extérieur (Symfony Messenger, Kafka, etc.) |
| ADR-004 | Stratégie de versioning des événements (upcasters) |
| ADR-005 | Gestion de l'idempotence des handlers de commande |
| ADR-006 | Choix de la bibliothèque OCR (Tesseract vs service cloud) |
| ADR-007 | Stratégie de reconstruction des projections (en ligne vs offline) |

---

## Annexe — Hiérarchie des erreurs de domaine

Les erreurs du domaine sont des **exceptions de domaine**, pas des erreurs d'infrastructure. Elles portent le code structuré correspondant.

```
KycDomainException (base)
├── InvariantViolationException
│   ├── InvalidTransitionException        ← transition non autorisée
│   └── OptimisticConcurrencyException    ← conflit de version event store
├── UploadRejectionException
│   ├── FileTooLargeException             (E_UPLOAD_SIZE)
│   ├── InvalidMimeTypeException          (E_UPLOAD_MIME)
│   ├── BlurryImageException              (E_UPLOAD_BLUR)
│   ├── LowResolutionException            (E_UPLOAD_DPI)
│   └── TooManyPagesException             (E_UPLOAD_PAGES)
├── OcrFailureException
│   ├── OcrTimeoutException               (E_OCR_TIMEOUT)
│   ├── LowOcrConfidenceException         (E_OCR_CONFIDENCE)
│   └── CorruptDocumentException          (E_OCR_CORRUPT)
└── ValidationRejectionException
    ├── UnderageApplicantException        (E_VAL_UNDERAGE)
    ├── DocumentExpiredException          (E_VAL_EXPIRED)
    ├── InvalidMrzException               (E_VAL_MRZ)
    └── InvalidFieldException             (E_VAL_NAME, E_VAL_DOC_ID)
```

---

*Document maintenu par l'équipe Fintech. Toute modification structurante doit s'accompagner d'un ADR. Les PRs doivent obtenir une revue d'un membre de l'équipe Domain & Architecture.*
