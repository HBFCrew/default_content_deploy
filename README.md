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
 

How does it work
----------------
This module allows build site totally without database transfer. 
Developer team can deploy all data via Git.

It requires Default content for D8 module and provide useful shortcuts 
for export/import function with drush and CI.

@todo


Requirements
------------
**Modules**
- default_content (http://link)
- file_entity (if you need export files)

**Sites synchronization settings**
@todo


Drush commands
--------------

**drush dcde**

Exports a single entity or group of entities to default content deploy module

Note:
- Don't use options *--entity_id* and *--bundle* together.

Examples:

    // Export all nodes
    drush dcder node
    
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
Identical options as in drush dcde

    drush dcder node
    drush dcder node --skip_entities=7,8
    drush dcder node --bundle=company --skip_entities=8
    drush dcder node --bundle=company --skip_entities=7,8
    drush dcder node --entity_id=8
    drush dcder node --entity_id=7,8

**drush dcdi**

Import all the content defined in a module.

**drush dcdes**

    drush dcdes --add_entity_type=media --skip_entity_type=file,node
...
@todo

**drush dcd-sync**

Set System Site, Admin and Anonymous UUIDs, Admin name.
Display current values (use without parameters).

Options:

     --name    The login name for Admin.
     --site    The system.site UUID.
     --uuid0   The UUID for Anonymous user.
     --uuid1   The UUID for Admin.

Examples:

    drush dcd-sync --name=admin --uuid0=21a8153ebb --uuid1=436ed2a --site=66baabd6
    drush dcd-sync --name=marty
    drush dcd-sync --uuid1=436ed2a


Workflow - how to export and deploy content
-------------------------------------------

@todo


Protecting content data files
---------------------------
There could be security problem, if anonymous user knows UUID of desired content
and knows it is stored in specific module, then user could determine the URL 
to desired content without permission.

Example of attacker URL: 
http://example.com/modules/custom/module_name/content/node/uuid_name.json

### Protection:
You can remove module on production server after use or you should secure access to data files via .htaccess or nginx configuration.

Example for Nginx host config:

    location ~ .*/default_content_deploy/.*.json$ {
      return 403;
    }

@todo Example of .htaccess

Maintainers
-----------
- Martin Klíma, (user profile)
- Jakub Hnilička,
- Radoslav Terezka,

This project has been sponsored by:
HBF s.r.o., http://hbf.sk/
@todo Company description