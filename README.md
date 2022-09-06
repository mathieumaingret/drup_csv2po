## Drush command

`` lando drush csv2po [name.csv] --options``

#### Prérequis :

-

### Options

Voir DrupCsv2poCommands.php

Par défaut le fichier se trouve dans /themes/DEFAULT/translations/translations.csv

**Exemple**

`` lando drush csv2po translations.csv --extension_type theme --extension_name backend``

**Exemple en dl depuis une url de google sheet**

``lando drush csv2po translations.csv --remote_csv_url https://docs.google.com/spreadsheets/d/XXX/gviz/tq\?tqx\=out:csv``

## CSV Format

#### Nom des colonnes (* obligatoire)

- EN (*) : traductions sources en anglais
- CONTEXT (*) : contextes de traduction
- PLURAL (*) : définit si rempli que la valeur est formattée singulier/pluriel
- PAGE : ajoute en commentaire si différent d'une traduction à une autre
- [LANGCODE] : une colonne nommée par le langcode sur 2 lettres pour chaque traduction

