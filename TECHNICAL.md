# Documentation technique DoliStockReturn

Ce document décrit l'architecture et les règles métier du module `dolistockreturn` pour faciliter sa maintenance.

## Objectif du module

`dolistockreturn` ajoute des actions de stock à partir des factures d'avoir Dolibarr :

- **Avoir client** lié à une facture client : création d'une entrée en stock.
- **Avoir fournisseur** lié à une facture fournisseur : création d'une sortie de stock.

Le module ne remplace pas les mécanismes natifs Dolibarr. Il ajoute une action manuelle contrôlée, accessible depuis les fiches facture client et facture fournisseur.

## Points d'entrée

### Descripteur module

Fichier : `core/modules/modDolistockreturn.class.php`

Rôles principaux :

- déclaration du module ;
- déclaration des hooks `invoicecard` et `invoicesuppliercard` ;
- déclaration des droits ;
- déclaration des constantes de configuration ;
- chargement des tables SQL à l'activation.

### Hooks UI

Fichier : `class/actions_dolistockreturn.class.php`

Méthodes importantes :

- `addMoreActionsButtons()` : affiche le bouton d'action ou le badge "déjà effectué".
- `formConfirm()` : affiche la fenêtre de confirmation Dolibarr.
- `doActions()` : exécute l'action confirmée et appelle le service métier.

Actions gérées :

- `dolistockreturn` puis `confirm_dolistockreturn` pour les avoirs clients.
- `dolistockreturn_supplier` puis `confirm_dolistockreturn_supplier` pour les avoirs fournisseurs.

Le hook lit `manual_fk_entrepot` en priorité, puis `fk_entrepot`. Cela permet de forcer un entrepôt manuel lorsque la résolution automatique est ambiguë.

### Service métier

Fichier : `class/stockreturnservice.class.php`

Classe : `DoliStockReturnService`

Elle contient toute la logique métier. Les hooks ne doivent pas dupliquer cette logique.

Méthodes publiques principales :

- `hasAlreadyReturned($creditNoteId, $objectType = 'customer_credit_note')`
- `getReturnIdForCreditNote($creditNoteId, $objectType = 'customer_credit_note')`
- `isEligibleCreditNote($creditNote)`
- `isEligibleSupplierCreditNote($creditNote)`
- `linesMatchSource($creditNote, $source)`
- `supplierLinesMatchSource($creditNote, $source)`
- `linesPartiallyMatchSource($creditNote, $source, $objectType = 'customer_credit_note')`
- `getReturnableLines($creditNote)`
- `getSupplierReturnableLines($creditNote)`
- `createStockReturn($creditNote, $warehouseId, $user)`
- `createSupplierStockOutput($creditNote, $warehouseId, $user)`

## Configuration

Les constantes principales sont :

- `DOLISTOCKRETURN_ENABLE_BUTTON`
- `DOLISTOCKRETURN_USE_SOURCE_WAREHOUSE`
- `DOLISTOCKRETURN_DEFAULT_WAREHOUSE`
- `DOLISTOCKRETURN_ENABLE_SUPPLIER_BUTTON`
- `DOLISTOCKRETURN_SUPPLIER_USE_SOURCE_WAREHOUSE`
- `DOLISTOCKRETURN_SUPPLIER_DEFAULT_WAREHOUSE`
- `DOLISTOCKRETURN_NON_STOCKABLE_POLICY`
- `DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES`

`DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES` est désactivé par défaut. Quand il est désactivé, le comportement historique strict est conservé : les lignes stockables de l'avoir doivent correspondre aux lignes stockables de la facture source.

## Tables du module

### `llx_dolistockreturn_return`

Table d'en-tête de traçabilité.

Champs importants :

- `object_type` : `customer_credit_note` ou `supplier_credit_note`.
- `direction` : `in` pour client, `out` pour fournisseur.
- `fk_credit_note` : id de l'avoir.
- `fk_source_invoice` : id de la facture source.
- `fk_entrepot` : entrepôt commun si applicable.
- `warehouse_mode` : `manual`, `source` ou `default`.

La contrainte unique est :

```sql
UNIQUE KEY uk_dolistockreturn_credit_note (object_type, fk_credit_note, entity)
```

Elle empêche de traiter deux fois le même avoir, mais autorise plusieurs avoirs différents sur une même facture source.

### `llx_dolistockreturn_returndet`

Table de détail.

Champs importants :

- `fk_return`
- `fk_credit_note_line`
- `fk_source_invoice_line`
- `fk_product`
- `fk_entrepot`
- `qty`
- `fk_stock_mouvement`
- `batch`

Convention métier :

- avoir client : `qty` positive ;
- avoir fournisseur : `qty` négative.

## Règles d'éligibilité

### Avoir client

Un avoir client est éligible si :

- il est de type `Facture::TYPE_CREDIT_NOTE` ;
- il est validé ;
- il est lié à une facture source via `fk_facture_source` ;
- il n'a pas déjà été traité ;
- il contient au moins une ligne produit stockable ;
- ses lignes respectent la politique stricte ou partielle.

### Avoir fournisseur

Un avoir fournisseur est éligible si :

- il est de type `FactureFournisseur::TYPE_CREDIT_NOTE` ;
- il est validé ;
- il est lié à une facture fournisseur source via `fk_facture_source` ;
- il n'a pas déjà été traité ;
- il contient au moins une ligne produit stockable ;
- ses lignes respectent la politique stricte ou partielle.

## Avoirs complets et partiels

### Mode strict

Mode par défaut.

Le service compare les lignes stockables de l'avoir et de la facture source avec agrégation par produit :

- mêmes produits ;
- mêmes quantités absolues.

Méthodes :

- `linesMatchSource()`
- `supplierLinesMatchSource()`

### Mode partiel V1

Activé par `DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES`.

La V1 est volontairement agrégée par produit. Pour chaque produit stockable de l'avoir :

- le produit doit exister sur la facture source ;
- la quantité de l'avoir doit être inférieure ou égale à la quantité source restante ;
- les quantités déjà traitées sont calculées depuis `llx_dolistockreturn_returndet` avec `ABS(qty)`.

Méthode :

- `linesPartiallyMatchSource()`

Limite V1 : si une facture source contient plusieurs lignes du même produit avec des lots ou des prix différents, le matching reste par produit. Une V2 pourrait introduire un matching par ligne source.

## Résolution des entrepôts

Le service utilise trois niveaux :

1. Entrepôt manuel fourni par l'utilisateur.
2. Entrepôt issu des mouvements source si l'option correspondante est activée.
3. Entrepôt par défaut configuré.

### Client

Méthode : `getSourceWarehouseMap()`

Origines couvertes :

- mouvement direct sur facture : `stock_mouvement.origintype = 'facture'`, `value < 0` ;
- commande liée à facture : `origintype = 'commande'`, `value < 0` ;
- livraison liée directement à facture : `origintype = 'shipping'`, `value < 0` ;
- livraison liée à une commande elle-même liée à la facture.

### Fournisseur

Méthode : `getSupplierSourceWarehouseMap()`

Origines couvertes :

- mouvement direct sur facture fournisseur : `origintype = 'invoice_supplier'`, `value > 0` ;
- commande fournisseur liée à facture fournisseur : `origintype = 'order_supplier'`, `value > 0` ;
- réception liée à une commande fournisseur elle-même liée à la facture fournisseur : `origintype = 'reception'`, `value > 0`.

Si plusieurs entrepôts sont trouvés pour un même produit, la résolution automatique est bloquée et l'utilisateur doit choisir un entrepôt manuel.

## Gestion des lots et numéros de série

Pour les produits soumis à lot/série (`Product::hasbatch()`), Dolibarr exige un lot lors des mouvements de stock.

V1 implémentée :

- le module lit le lot dans `llx_stock_mouvement.batch` sur les mouvements source ;
- il filtre par produit, entrepôt et sens du mouvement ;
- il accepte automatiquement uniquement si un seul lot source couvre toute la quantité de la ligne d'avoir ;
- il bloque si aucun lot n'est trouvé ;
- il bloque si plusieurs lots sont possibles ;
- il bloque si le lot unique ne couvre pas la quantité ;
- le lot utilisé est enregistré dans `llx_dolistockreturn_returndet.batch`.

Méthodes :

- `resolveBatchForLine()`
- `getSourceBatchRows()`

Le module ne propose pas encore de choix de lot en UI. C'est volontaire pour éviter une allocation arbitraire risquée sur les avoirs partiels.

## Mouvements de stock créés

### Client

Méthode : `createStockReturn()`

Dolibarr :

```php
MouvementStock::reception()
```

Origine du mouvement :

```php
$movement->setOrigin($creditNote->element, $creditNote->id);
```

Traçabilité :

- `object_type = customer_credit_note`
- `direction = in`
- détail `qty > 0`

### Fournisseur

Méthode : `createSupplierStockOutput()`

Dolibarr :

```php
MouvementStock::livraison()
```

Origine du mouvement :

```php
$movement->setOrigin($creditNote->element, $creditNote->id);
```

Traçabilité :

- `object_type = supplier_credit_note`
- `direction = out`
- détail `qty < 0`

## Prise en compte de Multicompany

Le service utilise `getEntity()` pour filtrer la traçabilité :

- client : `getEntity('invoice')`
- fournisseur : `getEntity('supplier_invoice')`

`supplier_invoice` est la clé canonique Dolibarr pour les factures fournisseurs. Certains contextes acceptent aussi `facture_fourn`, mais le service utilise la clé canonique.

## Migration et compatibilité

La méthode privée `ensureGenericTraceabilitySchema()` sécurise les installations qui auraient une ancienne version client uniquement :

- ajout de `object_type` si absent ;
- ajout de `direction` si absent ;
- reconstruction de l'index unique si nécessaire.

Cette méthode est appelée par les points d'entrée de traçabilité.

## Tests

La suite PHPUnit est dans :

```text
test/phpunit/tests/StockReturnServiceTest.php
```

Elle utilise le bootstrap Dolibarr et `phpunit-10.5.phar` disponible dans le module `dolilnd`.

Commande de référence :

```powershell
php ..\dolilnd\test\phpunit\phpunit-10.5.phar --configuration test\phpunit\phpunit.xml test\phpunit\tests\StockReturnServiceTest.php --display-warnings --display-deprecations
```

La couverture actuelle valide notamment :

- création client depuis facture, commande et livraison ;
- création fournisseur depuis facture fournisseur, commande fournisseur et réception ;
- blocage des entrepôts source ambigus ;
- priorité au choix manuel d'entrepôt ;
- traçabilité `hasAlreadyReturned()` et `getReturnIdForCreditNote()` ;
- mode strict ;
- mode partiel V1 ;
- produits soumis à lot/série avec lot unique ;
- blocage si plusieurs lots source sont possibles ;
- politique lignes non stockables.

## Points d'attention pour maintenance

- Ne pas déplacer la logique métier dans les hooks UI : elle doit rester dans `DoliStockReturnService`.
- Toute nouvelle origine de mouvement stock doit être ajoutée à la fois dans la résolution d'entrepôt et dans la résolution de lot.
- Toute évolution des avoirs partiels par ligne doit revoir `findSourceLineId()` et le matching actuellement agrégé par produit.
- Toute évolution vers le choix de lot en UI devra probablement ajouter une structure de données POST par ligne ou par couple produit-lot.
- Les sorties fournisseurs doivent continuer à écrire des quantités négatives dans `llx_dolistockreturn_returndet`.
- Les tests doivent être exécutés après toute modification du service, car beaucoup de règles sont interdépendantes.

