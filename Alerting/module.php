<?php

declare(strict_types=1);

class Alerting extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyInteger('ActivateDelay', 10);

        //Variables
        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 10);
        $this->EnableAction('Active');
        $this->RegisterVariableString('DelayDisplay', $this->Translate('Time to Activation'), '', 20);
        $this->RegisterVariableBoolean('Alert', $this->Translate('Alert'), '~Alert', 30);
        $this->EnableAction('Alert');
        $this->RegisterVariableString('ActiveSensors', $this->Translate('Active Sensors'), '~TextBox', 40);

        //Attributes
        $this->RegisterAttributeInteger('LastAlert', 0);

        //Timer
        $this->RegisterTimer('Delay', 0, 'ARM_Activate($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateDisplay', 0, 'ARM_UpdateDisplay($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        $targets = json_decode($this->ReadPropertyString('Targets'));

        //Update active sensors
        $this->updateActive();

        //DelayDispaly
        $this->SetBuffer('Active', json_encode($this->GetValue('Active')));
        $this->stopDelay();

        //Deleting all References
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        //Adding references for targets
        foreach ($targets as $target) {
            $this->RegisterReference($target->ID);
        }
        foreach ($sensors as $sensor) {
            $this->RegisterMessage($sensor->ID, VM_UPDATE);
            $this->RegisterReference($sensor->ID);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);

        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        foreach ($sensors as $sensor) {
            if ($sensor->ID == $SenderID) {
                $this->triggerAlert($sensor->ID, GetValue($sensor->ID));
                $this->updateActive();
                return;
            }
        }
    }

    public function SetAlert(bool $Status)
    {
        $targets = json_decode($this->ReadPropertyString('Targets'));

        //Lets notify all target devices
        foreach ($targets as $targetID) {
            //only allow links
            if (IPS_VariableExists($targetID->ID)) {
                $v = IPS_GetVariable($targetID->ID);
                $profileName = $this->GetProfileName($v);

                //If we somehow do not have a profile take care that we do not fail immediately
                $isReversed = $this->profileInverted($targetID->ID);
                if ($profileName != '') {
                    $variableProfile = IPS_GetVariableProfile($profileName);
                    //If we are enabling analog devices we want to switch to the maximum value (e.g. 100%)
                    if ($Status != $isReversed) {
                        $actionValue = $variableProfile['MaxValue'];
                    } else {
                        $actionValue = $variableProfile['MinValue'];
                    }
                    //Reduce to boolean if required

                    if ($v['VariableType'] == 0) {
                        $actionValue = $isReversed ? !$Status : $Status;
                    }
                } else {
                    $actionValue = $Status;
                }
                RequestAction($targetID->ID, $actionValue);
            }
        }

        SetValue($this->GetIDForIdent('Alert'), $Status);
    }

    public function GetLastAlertID()
    {
        return $this->ReadAttributeInteger('LastAlert');
    }

    public function ConvertToNewVersion()
    {
        if (@IPS_GetObjectIDByIdent('Sensors', $this->InstanceID) !== false) {
            IPS_SetProperty($this->InstanceID, 'Sensors', json_encode($this->convertLinksToTargetIDArray('Sensors')));
        }

        if (@IPS_GetObjectIDByIdent('Targets', $this->InstanceID) !== false) {
            IPS_SetProperty($this->InstanceID, 'Targets', json_encode($this->convertLinksToTargetIDArray('Targets')));
        }

        IPS_ApplyChanges($this->InstanceID);

        echo $this->Translate('Converting successful! Please reopen this configuration page. If everything is correct, all events and categories of this instance can be deleted');
    }

    public function SetActive(bool $Value)
    {
        SetValue($this->GetIDForIdent('Active'), $Value);
        if (!$Value) {
            $this->SetBuffer('Active', json_encode(false));
            $this->SetAlert(false);
            $this->stopDelay();
            return;
        }

        //Start activation process only if not already active
        if (!json_decode($this->GetBuffer('Active'))) {

            //Only start with delay when delay is > 0
            if ($this->ReadPropertyInteger('ActivateDelay') > 0) {
                $this->startDelay();
            } else {
                $this->SetBuffer('Active', json_encode(true));
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetActive($Value);
                break;
            case 'Alert':
                $this->SetAlert($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    public function Activate()
    {
        $this->SetBuffer('Active', json_encode(true));
        $this->stopDelay();
    }

    public function UpdateDisplay()
    {
        if (json_decode($this->GetBuffer('TimeActivated')) <= time()) {
            $this->stopDelay();
            return;
        }
        $secondsRemaining = json_decode($this->GetBuffer('TimeActivated')) - time();
        $this->SetValue('DelayDisplay', sprintf('%02d:%02d:%02d', ($secondsRemaining / 3600), ($secondsRemaining / 60 % 60), $secondsRemaining % 60));
    }

    public function GetConfigurationForm()
    {
        if ($this->ReadPropertyString('Sensors') == '[]' && $this->ReadPropertyString('Targets') == '[]') {
            $sensorsID = @IPS_GetObjectIDByIdent('Sensors', $this->InstanceID);
            $targetsID = @IPS_GetObjectIDByIdent('Targets', $this->InstanceID);
            if ($sensorsID !== false || $targetsID !== false) {
                if (IPS_CategoryExists($sensorsID) || IPS_CategoryExists($targetsID)) {
                    if (IPS_HasChildren($sensorsID) || IPS_HasChildren($targetsID)) {
                        $formconvert = [];
                        $formconvert['elements'][] = ['type' => 'Button', 'label' => $this->Translate('Convert'), 'onClick' => 'ARM_ConvertToNewVersion($id)'];
                        return json_encode($formconvert);
                    }
                }
            }
        }

        $formdata = json_decode(file_get_contents(__DIR__ . '/form.json'));

        //Annotate existing elements
        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        foreach ($sensors as $sensor) {
            //We only need to add annotations. Remaining data is merged from persistance automatically.
            //Order is determinted by the order of array elements
            if (IPS_ObjectExists($sensor->ID) && $sensor->ID !== 0) {
                $status = 'OK';
                $rowColor = '';
                if (!IPS_VariableExists($sensor->ID)) {
                    $status = $this->Translate('Not a variable');
                    $rowColor = '#FFC0C0';
                }

                $formdata->elements[1]->values[] = [
                    'Status' => $status,
                ];
            } else {
                $formdata->elements[1]->values[] = [
                    'rowColor' => '#FFC0C0',
                ];
            }
        }

        //Annotate existing elements
        $targets = json_decode($this->ReadPropertyString('Targets'));
        foreach ($targets as $target) {
            //We only need to add annotations. Remaining data is merged from persistance automatically.
            //Order is determinted by the order of array elements
            if (IPS_ObjectExists($target->ID) && $target->ID !== 0) {
                $status = 'OK';
                $rowColor = '';
                if (!IPS_VariableExists($target->ID)) {
                    $status = $this->Translate('Not a variable');
                    $rowColor = '#FFC0C0';
                } elseif (!HasAction($target->ID)) {
                    $status = $this->Translate('No action set');
                    $rowColor = '#FFC0C0';
                }

                $formdata->elements[3]->values[] = [
                    'Name'     => IPS_GetName($target->ID),
                    'Status'   => $status,
                    'rowColor' => $rowColor,
                ];
            } else {
                $formdata->elements[3]->values[] = [
                    'Name'     => $this->Translate('Not found!'),
                    'rowColor' => '#FFC0C0',
                ];
            }
        }

        return json_encode($formdata);
    }

    private function triggerAlert($sourceID, $sourceValue)
    {

        //Only enable alarming if our module is active
        if (!json_decode($this->GetBuffer('Active'))) {
            return;
        }

        if ($this->getAlertValue($sourceID, $sourceValue)) {
            $this->WriteAttributeInteger('LastAlert', $sourceID);
            $this->SetAlert(true);
        }
    }

    private function convertLinksToTargetIDArray($CategoryIdent)
    {
        $linkArray = [];
        $linkIDs = IPS_GetChildrenIDs(@IPS_GetObjectIDByIdent($CategoryIdent, $this->InstanceID));
        foreach ($linkIDs as $linkID) {
            if (IPS_LinkExists($linkID)) {
                $linkArray[] = ['ID' => IPS_GetLink($linkID)['TargetID']];
            }
        }

        return $linkArray;
    }

    private function GetProfileName($Variable)
    {
        if ($Variable['VariableCustomProfile'] != '') {
            return $Variable['VariableCustomProfile'];
        } else {
            return $Variable['VariableProfile'];
        }
    }

    private function profileInverted($VariableID)
    {
        return substr($this->GetProfileName(IPS_GetVariable($VariableID)), -strlen('.Reversed')) === '.Reversed';
    }

    private function getAlertValue($variableID, $value)
    {
        switch ($this->GetProfileName(IPS_GetVariable($variableID))) {
            case '~Window.Hoppe':
                return ($value == 0) || ($value == 2);

            case '~Window.HM':
                return ($value == 1) || ($value == 2);

            default:
                if ($this->profileInverted($variableID)) {
                    return !boolval($value);
                } else {
                    return boolval($value);
                }
        }
    }

    private function updateActive()
    {
        $sensors = json_decode($this->ReadPropertyString('Sensors'), true);

        $activeSensors = '';
        foreach ($sensors as $sensor) {
            $sensorID = $sensor['ID'];
            if ($this->getAlertValue($sensorID, GetValue($sensorID))) {
                $activeSensors .= '- ' . IPS_GetLocation($sensorID) . "\n";
            }
        }
        if ($activeSensors == '') {
            IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), true);
            return;
        }

        $this->SetValue('ActiveSensors', $activeSensors);
        IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), false);
    }

    private function startDelay()
    {
        //Display Delay
        $this->SetBuffer('TimeActivated', json_encode(time() + $this->ReadPropertyInteger('ActivateDelay')));

        //Unhide countdown and update it the first time
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), false);
        $this->UpdateDisplay();

        //Start timers for display update and activation
        $this->SetTimerInterval('UpdateDisplay', 1000);
        $this->SetTimerInterval('Delay', $this->ReadPropertyInteger('ActivateDelay') * 1000);
    }

    private function stopDelay()
    {
        $this->SetTimerInterval('Delay', 0);
        $this->SetTimerInterval('UpdateDisplay', 0);
        $this->SetValue('DelayDisplay', '00:00:00');
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), true);
    }
}
