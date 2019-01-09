CREATE TABLE persistence(
	created_at	TIMESTAMP	DEFAULT	CURRENT_TIMESTAMP()	NOT NULL,
	topic		VARCHAR(72)	NOT NULL,
	value		TEXT		NOT NULL,
	PRIMARY	KEY	(CREATED_AT, TOPIC)
);
CREATE TABLE current_state(
	topic		VARCHAR(72)	KEY,
	value		TEXT,
	edited_at	TIMESTAMP DEFAULT current_timestamp() ON UPDATE	current_timestamp()	NOT NULL
);
DELIMITER //
CREATE OR REPLACE TRIGGER log_to_persistence
BEFORE UPDATE ON current_state
FOR EACH ROW
BEGIN
  IF NEW.value != OLD.value THEN
	INSERT INTO persistence (created_at, topic, value) 
    VALUES( OLD.edited_at, OLD.topic, OLD.value );
  END IF;
END; //
DELIMITER ;
    