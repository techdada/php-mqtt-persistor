## php-mqtt-persistor

Persists topics received via MQTT for at least 24h. Priorized topics can be defined that are kept until deleted.

# Setup
To run, create a config file like this:

```
broker=my.mqtt.broker.com
broker_port=8883
#optional:
#broker_tls=tlsv1.2
#broker_user=
#broker_pass=

# connection info for used database.
persistence_db=mqtt_persistence
persistence_dbuser=mqtt_persistence
persistence_dbpassword=mqtt_persistence

# priorized topics kept for uncertain period of time. (just not dropped after 24h):
priority_topic0=topic1/subtopic1/value
priority_topic1=topic2/subtopic2/value2
# ...
# and so on.. the suffix number needs to be continuous, once one is not found by the program it stops detecting.
```

Place the configuration file direct in the programs path, or under ~/.config/phpMQTTBridge/config.properties. 

Once done, create a new database by logging into mysql with appropriate permissions:
```
CREATE DATABASE mqtt_persistence CHARACTER SET 'utf-8' DEFAULT COLLATION = 'utf8_generic_ci';
```
Then, create a user:
```
CREATE USER mqtt_persistence IDENTIFIED BY 'mqtt_persistence';
```
and import the database structure:

```
mysql -umqtt_persistence -p -D mqtt_persistence < structure.sql
```



