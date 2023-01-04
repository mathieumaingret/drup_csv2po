## Prérequis :

***Modules contrib :***
- drush

***Configurations***

Ajouter la configuration suivante à my_module.info.yml pour indiquer à Drupal la localisation des fichiers de traduction du thème ou module :

```yaml
'interface translation project': my_module
'interface translation server pattern': modules/custom/my_module/translations/%project.%language.po
```

## Commandes

```shell
drush csv2po --options
```

### Options

Voir DrupCsv2poCommands.php

- extension_type => 'theme', // theme or module
- extension_name => null, // theme or module machine name
- extension_translations_directory => 'translations', // translation directory name
- csv_remote_url => null, // download csv content from remote url before converting
- csv_output_filename => 'translations.csv', // save remove csv content to local file
- translations_replace_all => TRUE, // erase all existant translation
- translations_allow_update => FALSE, // allow to update existing translation if they exist, or force to create new ones
- plural_value_separator => PHP_EOL // separator for multiple cell values

Par défaut le fichier se trouve dans /themes/DEFAULT/translations/translations.csv

**Exemples**

***Thème personnalisé :***
`` lando drush csv2po --extension_type theme --extension_name backend``

***Module personnalisé***
`` lando drush csv2po --extension_type module --extension_name my_module``

***Exemple en dl depuis une url de google sheet***

``lando drush csv2po --csv_remote_url https://docs.google.com/spreadsheets/d/XXX/gviz/tq\?tqx\=out:csv``

## CSV Format

#### Nom des colonnes (* obligatoire)

- EN (*) : traductions sources en anglais
- CONTEXT (*) : contextes de traduction
- PLURAL (*) : définit si rempli que la valeur est formattée singulier/pluriel
- PAGE : ajoute en commentaire si différent d'une traduction à une autre
- [LANGCODE] : une colonne nommée par le langcode sur 2 lettres pour chaque traduction

***Exemple de CSV***

"PAGE","CONTEXT","PLURAL","EN","FR","DE","ES","IT","PL"
"FAQ","","","Frequently asked questions","Questions fréquentes","Häufig gestellte Fragen","Preguntas frecuentes","Domande frequenti","Najczęściej zadawane pytania"
"","","","Search by keywords","Rechercher par mots-clés","Nach Stichwörtern suchen","Búsqueda por palabras clave","Ricerca per parole chiave","Wyszukiwanie według słów kluczowych"
