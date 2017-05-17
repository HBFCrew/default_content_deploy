Default content deploy
======================
A default content deploy solution for Drupal 8.

* Introduction
* Requirements
* Recommended modules
* Installation
* Configuration
* Troubleshooting
* FAQ
* Maintainers
 

Introduction
------------
This module allows build site totally without database transfer. 
Developer team can deploy all content via Git.

It requires Default content for D8 module and provide useful shortcuts 
for export/import function with drush and CI.

@todo


Requirements
------------
**Modules**
- default_content (http://link)
- file_entity (if you need export/import files, f.e. images, attachments)
    - You need patch from https://www.drupal.org/node/2877678 due to old dependency since Drupal core 8.3.0.

**Sites synchronization settings**
Deprecated: For successful syncing content between sites, you need to have identical UUIDs for
Admin user and Anonymous user. @see drush dcd-sync command.

@todo Use config-set Site UUID


Install
-------
Type `composer require jakubhnilicka/default-content-deploy` in your project root
@todo Replace with new repository URL.

Configuration
-------------
Set DCD content directory in settings.php. We recommend place directory out of the document root. 

Example:

        $config_directories['content'] = '../content';


Drush commands
--------------

**drush dcde**

Exports a single entity or group of entities to default content deploy module 
(without any reference).

Note:
- Don't use options *--entity_id* and *--bundle* together.

Examples:

    //Export all nodes
    drush dcde node
    
    //Export all nodes with content-type company
    drush dcde node --bundle=company
    
    //Export all nodes with content-type company and skip node with nid 8
    drush dcde node --bundle=company --skip_entities=8 
    
    //Skiped entities can be defined by list of id.
    drush dcde node --bundle=company --skip_entities=7,8
    
    //Export entity with id 8
    drush dcde node --entity_id=8
    
    // Entities can be defined by list of id.
    drush dcde node --entity_id=7,8
    

**drush dcder**

Exports a single entity or group of entities with all references.
Identical options as in drush dcde.

    drush dcder node
    drush dcder node --skip_entities=7,8
    drush dcder node --bundle=company --skip_entities=8
    drush dcder node --bundle=company --skip_entities=7,8
    drush dcder node --entity_id=8
    drush dcder node --entity_id=7,8


**drush dcdes**

Exports a whole site content to default content deploy module.
You can skip several entities by their type.

@todo Specify default implemented types. 

    drush dcdes --add_entity_type=media --skip_entity_type=file,node
    
    
**drush dcdea**

Exports path aliases.

...
@todo


**drush dcdia**

Import path aliases from alias/url_aliases.json.


**drush dcdi**

Import all the content defined in a module.

*Important Import rules:*
- Entity is determined by UUID (new or existing).
- ID of entity is preserved, so entity can not change its ID.
- New entity is created as new entity with given ID.
- Existing entity is updated if imported entity is newer (by time changed).
- Existing entity with the same time is skipped.
- If the new entity ID is already occupied by some existing entity, it is skipped.
- This behavior can be changed by parameter *--force-update*

*drush dcdi --force-update*
- Existing entity is overwritten by the imported entity 
  (the old entity is deleted and a new entity with the same ID is created from imported JSON file).
- There is an exception for the user-type entity that only updates the UUID and the name, 
  because overwriting a user entity would result in blocked user without password and email 
  (the user entity export JSON file doesn't contain these informations).

*drush dcdi --verbose*
- Print detailed information about importing entities.

Examples:

    drush dcdi
    drush dcdi --force-update
    drush dcdi --verbose
    drush dcdi --verbose --force-update



**drush dcd-uuid-info**

Get System Site, Admin and Anonymous UUIDs, Admin name.
Display current values.

Examples:

    drush dcd-uuid-info


Workflow - how to export and deploy content
-------------------------------------------

@todo Git workflow
@todo Jenkins workflow


Protecting exported content data files
--------------------------------------
There could be security problem, if anonymous user knows UUID of desired content
and knows it is stored in specific module, then user could determine the URL 
to desired content without permission.

Example of attacker URL: 
http://example.com/modules/custom/module_name/content/node/uuid_name.json

### Security:
Place module content directory out of server document root. It should not be accessible from web server.
If it is not possible, you should secure access to content data files via .htaccess or nginx configuration.

Example for Nginx host config:

    location ~ .*/default_content_deploy_content/.*.json$ {
      return 403;
    }

@todo Example for .htaccess

Maintainers
-----------
- Martin Klíma, https://www.drupal.org/u/martin_klima, martin.klima@hqis.cz
- Jakub Hnilička, https://www.drupal.org/u/hnilickajakub
- Radoslav Terezka,

Sponsor
-------
This project has been sponsored by:
HBF s.r.o., http://hbf.sk/

We provide flexible easy-to-use web solutions for your company.
Our mission is to help you run your business in online world with attractive and perfectly working website.