<?php

require_once __DIR__.'/../DenonClass.php';  // diverse Klassen

class DenonSplitterTelnet extends IPSModule
{
    const STATUS_INST_IS_ACTIVE = 102; //Instanz aktiv
    const STATUS_INST_IS_INACTIVE = 104;
    const STATUS_INST_IP_IS_EMPTY = 202;
    const STATUS_INST_CONNECTION_LOST = 203;
    const STATUS_INST_IP_IS_INVALID = 204; //IP Adresse ist ungültig

    private $debug = false;

public function __construct($InstanceID)
{
    parent::__construct($InstanceID);

    if (file_exists(IPS_GetLogDir().'denondebug.txt')){
        $this->debug = true;
    }
}

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //These lines are parsed on Symcon Startup or Instance creation
        //You cannot use variables here. Just static values.

        // ClientSocket benötigt
        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); //Clientsocket

        $this->RegisterPropertyString('Host', '192.168.x.x');
        $this->RegisterPropertyInteger('Port', 23);

        //we will set the instance status when the parent status changes
        $this->RegisterMessage($this->GetParent(), 10505); //IM_CHANGESTATUS
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($this->debug) {
            IPS_LogMessage(get_class().'::'.__FUNCTION__, 'SenderID: '.$SenderID.', Message: '.$Message.', Data:'.json_encode($Data));
        }

        switch ($Message) {
            case 10505: //IM_CHANGESTATUS
                $this->ApplyChanges();
                break;
            default:
                trigger_error('Unexpected Message: '.$Message);
         }
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $PropertyChanged = false;
        $this->RegisterVariableString('InputMapping', 'Input Mapping', '', 1);
        IPS_SetHidden($this->GetIDForIdent('InputMapping'), true);

        $this->RegisterVariableString('AVRType', 'AVRType', '', 2);
        IPS_SetHidden($this->GetIDForIdent('AVRType'), true);

        //IP Prüfen
        $ip = $this->ReadPropertyString('Host');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {

            // Zwangskonfiguration des ClientSocket
            $ParentID = $this->GetParent();
            if ($ParentID) {
                if (IPS_GetProperty($ParentID, 'Host') != $this->ReadPropertyString('Host')) {
                    IPS_SetProperty($ParentID, 'Host', $this->ReadPropertyString('Host'));
                    $PropertyChanged = true;
                }
                if (IPS_GetProperty($ParentID, 'Port') != $this->ReadPropertyInteger('Port')) {
                    IPS_SetProperty($ParentID, 'Port', $this->ReadPropertyInteger('Port'));
                    $PropertyChanged = true;
                }

                $ParentOpen = $this->HasActiveParent($this->GetParent());

                // Keine Verbindung erzwingen wenn IP leer ist, sonst folgt später Exception.

                if (!$ParentOpen) {
                    $this->SetStatus(self::STATUS_INST_IS_INACTIVE);
                }

                if ($this->ReadPropertyString('Host') == '') {
                    $this->SetStatus(self::STATUS_INST_IP_IS_EMPTY);
                }

                if ($PropertyChanged) {
                    IPS_ApplyChanges($ParentID);
                }

                // Wenn I/O verbunden ist

                if ($this->HasActiveParent($ParentID)) {
                    //Instanz aktiv
                    $this->SetStatus(self::STATUS_INST_IS_ACTIVE);

                    //ein eventuell bestehender Timer aus Vorgängerversionen wird gelöscht, da nicht benötigt
                    $this->UnRegisterTimer('Update');
                }
            }
        } else {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP Adresse ist ungültig
        }
    }

    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:.
     */

    protected function RegisterTimer($Ident, $Milliseconds, $ScriptText)
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);

        if ($id && IPS_GetEvent($id)['EventType'] != 1) {
            IPS_DeleteEvent($id);
            $id = 0;
        }

        if (!$id) {
            $id = IPS_CreateEvent(1);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $Ident);
        }

        IPS_SetName($id, $Ident);
        IPS_SetHidden($id, true);
        IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$ScriptText;");

        if (!IPS_EventExists($id)) {
            throw new Exception("Ident with name $Ident is used for wrong object type");
        }
        if (!($Milliseconds > 0)) {
            IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);
            IPS_SetEventActive($id, false);
        } else {
            IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $Milliseconds);
            IPS_SetEventActive($id, true);
        }
    }

    private function UnRegisterTimer($Ident)
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);

        if ($id && IPS_GetEvent($id)['EventType'] == 1) {
            IPS_DeleteEvent($id);
        }

        if (IPS_EventExists($id)) {
            throw new Exception("Ident with name $Ident is used for wrong object type");
        }
    }

    /**
     * @param string $MappingInputs Input MappingInputs als JSON
     *
     * @return bool
     */
    public function SaveInputVarmapping(string $MappingInputs)
    {
        if ($MappingInputs == 'null') {
            trigger_error('MappingInputs is NULL');

            return false;
        }

        $idInputMapping = $this->GetIDForIdent('InputMapping');
        if ($idInputMapping) {
            $InputsMapping = GetValue($idInputMapping);
            if (($InputsMapping !== '') && ($InputsMapping !== 'null')) { //Auslesen wenn Variable nicht leer
                $Writeprotected = json_decode($InputsMapping)->Writeprotected;
                if (!$Writeprotected) { // Auf Schreibschutz prüfen
                    SetValueString($this->GetIDForIdent('InputMapping'), $MappingInputs);
                    SetValueString($this->GetIDForIdent('AVRType'), json_decode($MappingInputs)->AVRType);
                }
            } else { // Schreiben wenn Variable noch nicht gesetzt
                SetValueString($this->GetIDForIdent('InputMapping'), $MappingInputs);
                SetValueString($this->GetIDForIdent('AVRType'), json_decode($MappingInputs)->AVRType);
            }

            return true;
        } else {
            trigger_error('InputMapping Variable not found!');

            return false;
        }
    }

    // Input MappingInputs als JSON
    public function SaveOwnInputVarmapping(string $MappingInputs)
    {
        if ($this->GetIDForIdent('InputMapping')) {
            $MappingInputsArr = json_decode($MappingInputs);
            $AVRType = $MappingInputsArr->AVRType;
            SetValueString($this->GetIDForIdent('InputMapping'), $MappingInputs);
            SetValueString($this->GetIDForIdent('AVRType'), $AVRType);
        }
    }

    public function GetInputArrayStatus()
    {
        $InputsMapping = json_decode(GetValue($this->GetIDForIdent('InputMapping')));

        //Varmapping generieren
        $AVRType = $InputsMapping->AVRType;
        $Writeprotected = $InputsMapping->Writeprotected;
        $Inputs = $InputsMapping->Inputs;
        $Varmapping = [];
        foreach ($Inputs as $Key => $Input) {
            $Command = $Input->Source;
            $Varmapping[$Command] = $Key;
        }
        $InputArray = ['AVRType' => $AVRType, 'Writeprotected' => $Writeprotected, 'Inputs' => $Inputs];

        return $InputArray;
    }

    public function GetInputVarMapping()
    {
        $InputsMapping = GetValueString($this->GetIDForIdent('InputMapping'));
        if ($this->debug) {
            IPS_LogMessage(get_class().'::'.__FUNCTION__, 'InputsMapping: '.$InputsMapping);
        }

        $InputsMapping = json_decode($InputsMapping);

        if (is_null($InputsMapping)) {
            trigger_error(__FUNCTION__.': InputMapping cannot be decoded');

            return false;
        }

        //Varmapping generieren
        $Inputs = $InputsMapping->Inputs;
        $Varmapping = [];
        foreach ($Inputs as $Key => $Input) {
            $Command = $Input->Source;
            if (array_key_exists($Command, DENON_API_Commands::$SIMapping)) {
                $Command = DENON_API_Commands::$SIMapping[$Command];
            }
            $Varmapping[$Command] = $Key;
        }

        return $Varmapping;
    }

    //################# DUMMYS / WOARKAROUNDS - protected

    protected function GetParent()
    {
        $instance = IPS_GetInstance($this->InstanceID);

        return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : false;
    }

    private function HasActiveParent($ParentID)
    {
        if ($ParentID > 0) {
            if (IPS_GetInstance($ParentID)['InstanceStatus'] == self::STATUS_INST_IS_ACTIVE) {
                return true;
            }
        }

        $this->SetStatus(self::STATUS_INST_CONNECTION_LOST);

        return false;
    }

    public function GetStatusHTTP()
    {
        $InputsMapping = json_decode(GetValue($this->GetIDForIdent('InputMapping')));

        if (!isset($InputsMapping->AVRType)) {
            if ($this->debug) {
                IPS_LogMessage(__FUNCTION__, 'AVRType not set!');

                return false;
            }
        }
        $AVRType = $InputsMapping->AVRType;

        if (AVRs::getCapabilities($AVRType)['httpMainZone'] !== DENON_HTTP_Interface::NoHTTPInterface) { //Nur Ausführen wenn AVR HTTP unterstützt
            // Empfangene Daten vom Denon AVR Receiver

            //Semaphore setzen
            if ($this->lock('HTTPGetState')) {
                // Daten senden
                try {
                    //Daten abholen
                    $DenonStatusHTTP = new DENON_StatusHTML();
                    $ipdenon = $this->ReadPropertyString('Host');
                    $AVRType = $this->GetAVRType();
                    $InputMapping = $this->GetInputVarMapping();
                    if ($InputMapping === false) {
                        //InputMapping konnte nicht geleden werden
                        return false;
                    }
                    $data = $DenonStatusHTTP->getStates($ipdenon, $InputMapping, $AVRType);
                    $this->SendDebug('HTTP States:', json_encode($data), 0);

                    // Weiterleitung zu allen Gerät-/Device-Instanzen
                    $this->SendDataToChildren(json_encode(['DataID' => '{7DC37CD4-44A1-4BA6-AC77-58369F5025BD}', 'Buffer' => $data])); //Denon Telnet Splitter Interface GUI
                } catch (Exception $exc) {
                    // Senden fehlgeschlagen
                    $this->unlock('HTTPGetState');

                    throw new Exception($exc);
                }
                $this->unlock('HTTPGetState');
            } else {
                $msg = 'Can not set lock \'HTTPGetState\'';
                echo $msg.PHP_EOL;

                throw new Exception($msg, E_USER_NOTICE);
            }

            return $data;
        }

        return false;
    }

    protected function RequireParent($ModuleID)
    {
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] == 0) {
            $parentID = IPS_CreateInstance($ModuleID);
            $instance = IPS_GetInstance($parentID);
            IPS_SetName($parentID, $instance['ModuleInfo']['ModuleName']);
            IPS_ConnectInstance($this->InstanceID, $parentID);
        }
    }

    protected function SetStatus($Status)
    {
        parent::senddebug(__FUNCTION__, 'Status: '.$Status, 0);

        if ($Status != IPS_GetInstance($this->InstanceID)['InstanceStatus']) {
            parent::SetStatus($Status);
        }
    }

    private function GetAVRType()
    {
        return GetValue($this->GetIDForIdent('AVRType'));
    }

    // Display NSE, NSA, NSH noch ergänzen

    //Tuner ergänzen

    //################# Datapoints

    // Data an Child weitergeben
    public function ReceiveData($JSONString)
    {

        // Empfangene Daten vom I/O
        $payload = json_decode($JSONString);
        $dataio = json_decode($this->GetBuffer(__FUNCTION__)).$payload->Buffer;
        $this->SetBuffer(__FUNCTION__, '');
        $this->SendDebug('Data from I/O:', json_encode($dataio), 0);

        // the received data must be terminated with \r
        if (substr($dataio, strlen($dataio) - 1) != "\r") {
            if ($this->debug) {
                IPS_LogMessage(get_class().'::'.__FUNCTION__, 'received data are buffered, because they are not terminated: '.json_encode($dataio));
            }
            $this->SetBuffer(__FUNCTION__, json_encode($dataio));

            return false;
        }

        //Daten aufteilen und Abschlusszeichen wegschmeißen
        $data = preg_split('/\r/', $dataio);
        array_pop($data);

        $this->SendDebug('Received Data:', json_encode($data), 0);
        if ($this->debug) {
            IPS_LogMessage(get_class().'::'.__FUNCTION__, 'received data: '.json_encode($data));
        }

        $APIData = new DenonAVRCP_API_Data($this->GetAVRType(), $data);

        $InputMapping = $this->GetInputVarMapping();
        $SetCommand = $APIData->GetCommandResponse($InputMapping);
        $this->SendDebug('Buffer IN:', json_encode($SetCommand), 0);

        // Weiterleitung zu allen Telnet Gerät-/Device-Instanzen wenn SetCommand gefüllt ist

        if ((count($SetCommand['Data']) > 0) || ($SetCommand['SurroundDisplay'] != '') || (count($SetCommand['Display']) > 0)){
            $this->SendDataToChildren(json_encode(['DataID' => '{7DC37CD4-44A1-4BA6-AC77-58369F5025BD}', 'Buffer' => $SetCommand])); //Denon Telnet Splitter Interface GUI
        }

        return true;
    }

    //################# DATAPOINT RECEIVE FROM CHILD

    public function ForwardData($JSONString)
    {

        // Empfangene Daten von der Device Instanz
        $data = json_decode($JSONString);
        $this->SendDebug('Command Out:', print_r($data->Buffer, true), 0);

        if ($this->debug) {
            IPS_LogMessage(get_class().'::'.__FUNCTION__, 'send data: '.$data->Buffer);
        }
        // Hier würde man den Buffer im Normalfall verarbeiten
        // z.B. CRC prüfen, in Einzelteile zerlegen

        try {
            // Weiterleiten zur I/O Instanz
            $resultat = $this->SendDataToParent(json_encode(['DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => $data->Buffer])); //TX GUID

            // Test Daten speichern
            //SetValue($this->GetIDForIdent("BufferIN"), $data->Buffer);
        } catch (Exception $ex) {
            echo $ex->getMessage();
            echo ' in '.$ex->getFile().' line: '.$ex->getLine().'.';

            return false;
        }

        // Weiterverarbeiten und durchreichen
        return $resultat;
    }

    //################# SEMAPHOREN Helper  - private

    private function lock($ident)
    {
        for ($i = 0; $i < 10; $i++) {
            if (IPS_SemaphoreEnter('DENONAVRT_'.(string) $this->InstanceID.(string) $ident, 1000)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }

        return false;
    }

    private function unlock($ident)
    {
        IPS_SemaphoreLeave('DENONAVRT_'.(string) $this->InstanceID.(string) $ident);
    }
}
