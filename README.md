## Prérequis :

***Modules contrib :***

- drush

***Configurations***

1. Ajouter la configuration suivante à my_module.info.yml pour indiquer à Drupal la localisation des fichiers de traduction
du thème ou module :

```yaml
'interface translation project': my_module
'interface translation server pattern': modules/custom/my_module/translations/%project.%language.po
```

2. /admin/config/regional/translate/settings : value overwrite = All

## Commandes

```shell
drush csv2po --options
```

### Options

Voir DrupCsv2PoConverter::$defaultOptions

Par défaut le fichier se trouve dans /themes/DEFAULT/translations/translations.csv

**Exemples**

***Thème personnalisé :***
`` lando drush csv2po --extension_type theme --extension_name backend``

***Module personnalisé***
`` lando drush csv2po --extension_type module --extension_name my_module``

***Exemple en dl depuis une url de google sheet***
``lando drush csv2po --csv_remote_url https://docs.google.com/spreadsheets/d/XXX/gviz/tq\?tqx\=out:csv``

***Options dans settings.php

```php
$settings['drup_csv2po']['csv_remote_url'] = 'https://docs.google.com/spreadsheets/d/[ID]/gviz/tq?tqx=out:csv';
$settings['drup_csv2po']['extension_type'] = 'module';
$settings['drup_csv2po']['extension_name'] = 'drup_translations';
```

## CSV Format

#### Nom des colonnes (* obligatoire)

- EN (*) : traductions sources en anglais
- CONTEXT (*) : contextes de traduction
- PLURAL (*) : définit si rempli que la valeur est formattée singulier/pluriel
- PAGE : ajoute en commentaire si différent d'une traduction à une autre
- [LANGCODE] : une colonne nommée par le langcode sur 2 lettres pour chaque traduction

***Exemple de CSV***

"PAGE","CONTEXT","PLURAL","EN","FR","DE","ES","IT","PL"
"FAQ","","","Frequently asked questions","Questions fréquentes","Häufig gestellte Fragen","Preguntas frecuentes","
Domande frequenti","Najczęściej zadawane pytania"
"","","","Search by keywords","Rechercher par mots-clés","Nach Stichwörtern suchen","Búsqueda por palabras clave","
Ricerca per parole chiave","Wyszukiwanie według słów kluczowych"
