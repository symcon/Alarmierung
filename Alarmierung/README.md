# Alarmierung
Das Modul löst einen Alarm aus, wenn eine der Sensorenvariablen aktiv wird.
Dabei werden Zielvariablen bei einem Alarm auf den maximalen Wert bzw. An (True) gesetzt.
Ein einmal geschalteter Alarm wird nicht automatisch deaktiviert, dieser muss manuell zurückgesetzt werden.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Konfiguration von Sensor- und Zielvariablen via Listenauswahl, welche den Alarm auslösen oder bei einem Alarm geschaltet werden.
* Einstellbare Einschaltverzögerung
* Ein-/Ausschaltbarkeit via WebFront-Button oder Skript-Funktion.
* Konvertierungsfunktion für alte Versionen des Alarmierungsmoduls

### 2. Voraussetzungen

- IP-Symcon ab Version 5.3

### 3. Software-Installation

* Über den Module Store das Modul Alarmierung installieren.
* Alternativ über das Module Control folgende URL hinzufügen:
`https://github.com/symcon/Alarmierung` 

### 4. Einrichten der Instanzen in IP-Symcon

- Unter "Instanz hinzufügen" kann das 'Alarmierung'-Modul mithilfe des Schnellfilters gefunden werden.
    - Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)  

__Konfigurationsseite__:

Name                   | Beschreibung
---------------------- | ---------------------------------
Button "Konvertierung" | (Wird nur angezeigt, wenn die Listen leer und alte Links vorhanden sind) Wenn eine alte Version des Moduls erkannt wurde, können die alten Links in die neuen Listen via Knopfdruck eingepflegt werden. Ist dies Erfolgreich erscheint ein Meldungsfenster.
Sensorvariablen        | Diese Liste beinhaltet die Variablen, welche bei Aktualisierung auf einen aktiven Wert einen Alarm auslösen. Als aktiv gelten hierbei Variablen mit dem Wert true oder einen Wert ungleich 0. Sollte die Variable ein .Reversed Profil haben gelten die Werte false und 0 als aktiv.
Zielvariablen          | Diese Liste beinhaltet die Variablen, welche bei Alarm geschaltet werden. Diese müssen eine Standardaktion oder Aktionsskript beinhalten.
Einschaltverzögerung   | Wenn größer 0 wird die Alarmierung erst nach der eingestellten Zeit aktiv

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

##### Statusvariablen

Name                 | Typ       | Beschreibung
---------------------| --------- | ----------------
Aktiv                | Boolean   | De-/Aktiviert die Alarmierung. Wird die Alarmierung deaktiviert, so wird auch der ggf. vorhandene Alarm deaktiviert sowie alle ausgewählten Zielvariablen.
Zeit bis Aktivierung | String    | Zeigt während des Aktivierungs-Vorgangs die noch verbleibende Zeit an.
Alarm                | Boolean   | De-/Aktiviert den Alarm und alle ausgewählten Zielvariablen.
Aktive Sensoren      | String    | Listet alle aktiven Sensoren auf und wird ausgeblenet, wenn keine Aktiv sind.


##### Profile:

Es werden keine zusätzlichen Profile hinzugefügt.

### 6. WebFront

Über das WebFront kann die Alarmierung de-/aktiviert werden.  
Es wird zusätzlich angezeigt, ob ein Alarm vorliegt oder nicht.
Es wird eine Liste aller noch aktieven Sensoren angezeigt.
Der Alarm kann auch manuell de-/aktiviert werden.

### 7. PHP-Befehlsreferenz

`boolean ARM_SetActive(integer $InstanzID, boolean $Value);`
Schaltet das Alarmierungsmodul mit der InstanzID $InstanzID  auf den Wert $Value (true = An; false = Aus).  
Die Funktion liefert keinerlei Rückgabewert.  
`ARM_SetActive(12345, true);`

`boolean ARM_SetAlert(integer $InstanzID, boolean $Value);`
Schaltet den Alarm mit der InstanzID $InstanzID auf den Wert $Value (true = An; false = Aus).  
Die Funktion liefert keinerlei Rückgabewert.  
`ARM_SetAlert(12345, false);`

`integer ARM_GetLastAlertID(integer $InstanzID);`
Gibt die ID der Variable zurück, die als letztes einen Alarm ausgelöst hat.
`ARM_GetLastAlertID(12345);`
