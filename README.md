[![Build Status](https://travis-ci.org/rjanot/yucca.png?branch=master)](https://travis-ci.org/rjanot/yucca)

Yucca
=====

Doctrine and Propel are well known ORM, they are great for websites which doesn't need hard optimizations. But which ORM
can you choose :
- when you have a site that handle 500 concurrent connections ?
- when you have sharded databases ?
- when you have datas simultaneously in SQL DB (MySQL, PostgreSQL, ...) and NoSql (Mongo, Redis, Memcache, Neo4J, ...) ?


Philosophy
==========

Yucca is designed to separate the PHP Model and the database Schema.
Most of actual ORM maps strictly the design of a table to the object properties. But is it really logic ?
Can't a model aggregate multiple tables ?
And why an object should be mapped to a table and not simultaneously to multiple table or a table and a cache layer ?

That's the reasons that makes me create Yucca.

Model
-----
A Yucca Model is an active record that is linked to one or more datasources.
For example, if you have a `user` table designed like this:
```
id INT PRIMARY
login VARCHAR(255)
password CHAR(40)
```

and a `user_params` table (which can handle fields like facebook_id or site theme to use for example) designed like this:
```
id INT PRIMARY
user_id INT
param_name VARCHAR(40)
param_value TEXT
```

You can tell yucca that the model `User` has 2 different sources mapped: `user` and `user_params`


Each of these sources have one or more handlers:
`user` source can read it's data from memcache and from a MySQL database. If memcache doesn't have datas, we check the
next one (MySQL) and if it has datas, we bring them to memcache and after hydrate our model.

Actually, 2 handlers are given: memcache and DoctrineDbal

The DoctrineDbal handler use schema information to know which table read. Table information are for sharded tables.
On huge systems, we can have for example 10 sharded tables representing the user. so for each shard we specify the
connection to use. Yucca select the shard using the given shard strategy.

To summarize, we have to configure:
- connections
- schema
- source
- mapping

See more in the configuration section

Models are hydrated by a lazy loading system.
This imply that getting an object with a given id doesn't make any call to the storage. The first call / connection will
occur when getting or setting a property.

The model can only be accessed by it's id, which is the DB primary key (maybe an array of 2 or more fields), or the key
in case of a Key/Value storage. By this way we can cache a row of data easily.

In case of reqesting a model by another field, we use Selectors and Iterators.

Selector
--------

A selector is a way of retrieving models by another field than the id.
But Selector doesn't retrieve datas, they only retrieve ids. So we can get model by their ids and use cache.

Iterator
--------

An iterator use a selector to get Ids and a SINGLE model (by default) which is hydrated, to preserve memory.


Configuration
=============

Connection
----------
```yaml
#app/config/yucca.yml
yucca:
    #DB connections
    connections:
        default:
            type: doctrine
            options:
                driver:   %database_default_driver%
                host:     %database_default_host%
                port:     %database_default_port%
                dbname:   %database_default_name%
                user:     %database_default_user%
                password: %database_default_password%
                charset:  UTF8
        ip2location:
            type: doctrine
            options:
                driver:   %database_ip2location_driver%
                host:     %database_ip2location_host%
                port:     %database_ip2location_port%
                dbname:   %database_ip2location_name%
                user:     %database_ip2location_user%
                password: %database_ip2location_password%
                charset:  UTF8
        memcache_general:
            type: memcache
            options:
                prefix: %memcache_general_prefix%
                servers:
                    - {port: %memcache_general_port%, host: %memcache_general_host%}

```

You can add multiple DB connections. You have to specify the connection `type` and the `options` that will be given to
the connection factory.
As it use a factory, you can add your own.

Schema
----------
```yaml
#app/config/yucca.yml
yucca:
    #DB Schema
    schema:
        user:
            sharding_strategy: modulo
            shards:
                - default
        user_params:
            sharding_strategy: modulo
            shards:
                - default


        #Geographic tables
        dbip_lookup:
            sharding_strategy: modulo
            shards:
                - ip2location
        province:
            sharding_strategy: modulo
            shards:
                - default
        state:
            sharding_strategy: modulo
            shards:
                - default
```

Here you describe your schema, telling the sharding strategy to use and which connection to use for each shards

Sources
----------
```yaml
#app/config/yucca.yml
yucca:
    #Sources
    sources:
        province:
            default_params:
                fields: { id: 'identifier', code: ~, name: ~ , uppercase_name: ~ , slug: ~ , state_id: {type: 'object', class_name: 'Fdf\Model\State'}}
            handlers:
                -
                    type: database_single_row
                    table_name: province
        state:
            default_params:
                fields: { id: 'identifier', name: ~ , uppercase_name: ~ , slug: ~ }
            handlers:
                -
                    type: database_single_row
                    table_name: state
        user:
            default_params:
                fields: { id: 'identifier', created_at: {type: 'datetime'}, updated_at: {type: 'datetime'}, email: ~, password: ~, salt: ~ }
            handlers:
                -
                    type: database_single_row
                    table_name: user
        user_params:
            default_params:
                fields: {id: 'identifier', fbuid: ~, twitteruid: ~}
                name_field: param_name
                value_field: param_value
                mapping: {id: user_id}
            handlers:
                -
                    type: database_multiple_row
                    table_name: user_params
```

This is the section where you describe each "tables" : in default params you have to tell fields handled by this source
and their respective types.
You also set the handlers. Most of given tables use a `database_single_row` handler, and the table name to use.
But for `user_params`, you have the `database_multiple_row` handler that automatically select multiple row having the
matching `user_id` and make a match between the `name_field` and `value_field`, like explained in the Philosophy section


Mapping
----------
```yaml
#app/config/yucca.yml
yucca:
    #Mapping object / Sources
    mapping:
        MyProject\Model\Province:
            mapper_class_name:
            properties:
                state:
                    field: state_id
            sources:
                - province
        MyProject\Model\State:
            mapper_class_name: ~
            properties: ~
            sources:
                - state
        MyProject\Model\User:
            mapper_class_name: ~
            properties: ~
            sources:
                - user



    #Selectors
    selectors:
        MyProject\Selector\Province:
            sources:
                - database
        MyProject\Selector\State:
            sources:
                - database
        MyProject\Selector\User:
            sources:
                - database

        MyProject\Selector\DbIpLookup:
            sources:
                - database
```

Use case
========

In the following source code, I'll use Symfony2 and it's Dependency Injection container.

I've an id, I want to get the object
------------------------------------

```php
$this->get('yucca.entity_manager')->load('YuccaDemo\\Model\\User', $user_id);
```
Note that in this case, no call to the underlying DBs are made.


I've an object, I want to know if it's records really exists
------------------------------------------------------------

```php
 try {
    $user->ensureExist();
} catch (\Yucca\Component\Source\Exception\NoDataException $e) {
    throw $e;
}
```
`ensureExist` loop upon each yucca managed properties and retrieve them from sources. If one is going to fail, then it
throw the `NoDataException`


I've an object, I want to get / set properties
----------------------------------------------

```php
$login = $user->getLogin();
$user->setLogin($login);
```
If it's a new object, nothing is done, but if it's a loaded object, if it has not been hydrated yet, it's automatically
hydrated.


I've an object, I want to save it
---------------------------------

```php
$this->get('yucca.entity_manager')->save($user);
```
If it was a new object, then if the id is an autogenerated ID, it's given back to the
