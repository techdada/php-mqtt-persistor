# php-mqtt-persistor

Persists topics received via MQTT for at least 24h. Priorized topics can be defined that are kept until deleted.

Persistence happens in two steps: All received topics and the messages are saved to the database table "current_state", similar to what the retain flag of MQTT does. The update timestamp is preserved ("edited_at").
On this table there is a trigger defined, that checks if the new value differs the old one. Only if this is the case, it inserts the old value to the table "persistence". 

This way some data is saved compared to storing each received message.

## Setup
To run, create a config file like this:

```
mqtt_qos=0
persisted_topic0=systems/#
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

to run, just type ./persistor.php

To have it run in the background of linux systems like e.g. Fedora/RHEL/CentOS or Ubuntu/Debian or other systemd-based distributions there is a systemd-unit file mqtt-persistor.service, which can be installed/activated like this (make sure you adapt the path inside to point to your directory):

```
cp mqtt-persistor.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now mqtt-persistor.service
```

For starting and stopping or getting status information:
```
systemctl start mqtt-persistor
systemctl stop ...
systemctl restart ...
systemctl status ...
```




## Reconnection and QoS

Set `mqtt_qos=0`, `1` or `2` in `config.properties` (default: `0`).
This is the requested QoS for all `persisted_topicN` subscriptions; actual
message delivery also depends on the publisher QoS and the broker grant.
Invalid settings stop startup with an error.

The client reconnects automatically, retries with delays from 1 to 30 seconds,
and subscribes again with the configured QoS. Network reads have timeouts.
QoS 1 sends PUBACK after the database callback completes. QoS 2 handles
PUBLISH/PUBREC/PUBREL/PUBCOMP and suppresses duplicate delivery within the
active session. QoS 2 pending messages are kept in memory (at most 64).
Clean sessions remain enabled: no offline queue or persistent-session guarantee
is provided, and QoS 1 can deliver duplicates. Database history is unchanged.

PHP CLI requires PDO MySQL and pcntl, plus OpenSSL for TLS. Tested with PHP 8.3.
Run the regression tests with `python3 tests/reconnect.py`,
`python3 tests/qos.py` and `php tests/database.php`.
