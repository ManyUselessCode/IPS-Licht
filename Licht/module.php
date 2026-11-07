<?php

declare(strict_types=1);

/**
 * Eine Instanz steuert eine Lampengruppe: Tasten schalten sie um, LEDs zeigen
 * ihren Zustand, Präsenzmelder oder Nachlauf schalten sie aus.
 * Der Zustand kommt immer aus den Status-Variablen, nie aus den Befehlsinstanzen.
 */
class Licht extends IPSModule
{
    private const LED_GRUEN = 3;
    private const LED_ROT = 2;
    private const STATUS_FEHLER = 201;
    private const BEFEHL_SPERRE_S = 5;      // so lange zählt eine Statusänderung als eigene
    private const START_WARTEN_MS = 30000;  // Gateway und Bus nach dem Start abwarten
    private const ANTWORT_WARTEN_MS = 5000; // Zeit für die Antworten auf die Leseanfragen

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Lampen', '[]');
        $this->RegisterPropertyString('Tasten', '[]');
        $this->RegisterPropertyString('LedsMdt', '[]');
        $this->RegisterPropertyString('LedsJung', '[]');
        $this->RegisterPropertyString('Melder', '[]');
        $this->RegisterPropertyInteger('Nachlauf', 0);

        $this->RegisterTimer('Nachlauf', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'NachlaufAbgelaufen\', 0);');
        $this->RegisterTimer('Start', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'StartSchritt\', 0);');

        $this->RegisterVariableBoolean('Licht', 'Licht', '~Switch', 1);
        $this->RegisterVariableString('Grund', 'Grund', '', 2);
        $this->EnableAction('Licht');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            foreach ($nachrichten as $nachricht) {
                $this->UnregisterMessage($sender, $nachricht);
            }
        }
        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Einrichten();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] == KR_READY) {
                $this->Einrichten();
                $this->SetBuffer('StartSchritt', 'Lesen');
                $this->SetTimerInterval('Start', self::START_WARTEN_MS);
            }
            return;
        }
        if ($Message != VM_UPDATE) {
            return;
        }

        foreach ($this->Liste('Tasten') as $taste) {
            if ($taste['VariableID'] == $SenderID) {
                $this->Umschalten($taste['Name']);
                return;
            }
        }
        if (!$Data[1]) {
            return; // Status und Melder zählen nur, wenn sich der Wert geändert hat
        }
        foreach ($this->Liste('Lampen') as $lampe) {
            if ($lampe['StatusID'] == $SenderID) {
                $this->StatusGeaendert((bool) $Data[0]);
                return;
            }
        }
        foreach ($this->Liste('Melder') as $melder) {
            if ($melder['VariableID'] == $SenderID) {
                $this->MelderGeaendert();
                return;
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Licht':
                $this->Schalten((bool) $Value, ($Value ? 'An' : 'Aus') . ': App');
                break;
            case 'NachlaufAbgelaufen':
                $this->SetTimerInterval('Nachlauf', 0);
                if ($this->IstAn()) {
                    $this->Schalten(false, 'Aus: Nachlauf ' . $this->ReadPropertyInteger('Nachlauf') . ' s');
                }
                break;
            case 'StartSchritt':
                $this->StartSchritt();
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    private function Einrichten(): void
    {
        $lampen = $this->Liste('Lampen');
        if (count($lampen) == 0) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $fehler = $this->Pruefen();
        if ($fehler != '') {
            $this->LogMessage($fehler, KL_ERROR);
            $this->SetStatus(self::STATUS_FEHLER);
            return;
        }

        foreach ($lampen as $lampe) {
            $this->RegisterMessage($lampe['StatusID'], VM_UPDATE);
            $this->RegisterReference($lampe['StatusID']);
            $this->RegisterReference($lampe['SchaltenID']);
        }
        foreach ($this->Liste('Tasten') as $taste) {
            $this->RegisterMessage($taste['VariableID'], VM_UPDATE);
            $this->RegisterReference($taste['VariableID']);
        }
        foreach ($this->Liste('Melder') as $melder) {
            $this->RegisterMessage($melder['VariableID'], VM_UPDATE);
            $this->RegisterReference($melder['VariableID']);
        }
        foreach ($this->Liste('LedsMdt') as $led) {
            $this->RegisterReference($led['InstanceID']);
        }
        foreach ($this->Liste('LedsJung') as $led) {
            $this->RegisterReference($led['RotID']);
            $this->RegisterReference($led['GruenID']);
        }

        $this->SetStatus(IS_ACTIVE);
        $this->ZustandZeigen();
        $this->NachlaufStarten();
    }

    private function Pruefen(): string
    {
        $falsch = [];
        foreach ($this->Liste('Lampen') as $lampe) {
            if (!IPS_InstanceExists($lampe['SchaltenID'])) {
                $falsch[] = 'Lampe Schalten ' . $lampe['SchaltenID'];
            }
            if (!IPS_VariableExists($lampe['StatusID'])) {
                $falsch[] = 'Lampe Status ' . $lampe['StatusID'];
            }
        }
        foreach ($this->Liste('Tasten') as $taste) {
            if (!IPS_VariableExists($taste['VariableID'])) {
                $falsch[] = 'Taste ' . $taste['VariableID'];
            }
        }
        foreach ($this->Liste('Melder') as $melder) {
            if (!IPS_VariableExists($melder['VariableID'])) {
                $falsch[] = 'Melder ' . $melder['VariableID'];
            }
        }
        foreach ($this->Liste('LedsMdt') as $led) {
            if (!IPS_InstanceExists($led['InstanceID'])) {
                $falsch[] = 'MDT-LED ' . $led['InstanceID'];
            }
        }
        foreach ($this->Liste('LedsJung') as $led) {
            if (!IPS_InstanceExists($led['RotID']) || !IPS_InstanceExists($led['GruenID'])) {
                $falsch[] = 'Jung-LED ' . $led['RotID'] . '/' . $led['GruenID'];
            }
        }
        return count($falsch) ? 'Ungültige IDs: ' . implode(', ', $falsch) : '';
    }

    private function Umschalten(string $name): void
    {
        $an = $this->IstAn();
        $this->Schalten(!$an, ($an ? 'Aus: ' : 'An: ') . $name);
    }

    private function Schalten(bool $wert, string $grund): void
    {
        $this->SetBuffer('Befehl', (string) time());
        $this->SetValue('Grund', $grund);
        foreach ($this->Liste('Lampen') as $lampe) {
            if (!@KNX_WriteDPT1($lampe['SchaltenID'], $wert)) {
                $this->LogMessage('Schalten fehlgeschlagen: ' . $lampe['SchaltenID'], KL_ERROR);
            }
        }
    }

    private function StatusGeaendert(bool $lampeAn): void
    {
        $war = $this->GetValue('Licht');
        $an = $this->IstAn();
        if ($an != $war) {
            $this->ZustandZeigen();
            if (time() - (int) $this->GetBuffer('Befehl') > self::BEFEHL_SPERRE_S) {
                $this->SetValue('Grund', ($an ? 'An' : 'Aus') . ': von Extern');
            }
        }
        if ($lampeAn || !$an) {
            $this->NachlaufStarten();
        }
    }

    private function MelderGeaendert(): void
    {
        if ($this->ReadPropertyInteger('Nachlauf') == 0 && $this->KeinePraesenz() && $this->IstAn()) {
            $this->Schalten(false, 'Aus: keine Präsenz');
        }
    }

    private function StartSchritt(): void
    {
        if ($this->GetBuffer('StartSchritt') == 'Lesen') {
            $this->SetBuffer('StartSchritt', 'Pruefen');
            $this->SetTimerInterval('Start', self::ANTWORT_WARTEN_MS);
            foreach ($this->Liste('Lampen') as $lampe) {
                $this->StatusAnfragen($lampe['StatusID']);
            }
            foreach ($this->Liste('Melder') as $melder) {
                $this->StatusAnfragen($melder['VariableID']);
            }
            return;
        }

        $this->SetTimerInterval('Start', 0);
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $this->ZustandZeigen();
        $this->NachlaufStarten();
        if ($this->ReadPropertyInteger('Nachlauf') == 0 && count($this->Liste('Melder')) > 0
            && $this->KeinePraesenz() && $this->IstAn()) {
            $this->Schalten(false, 'Aus: keine Präsenz beim Start');
        }
    }

    private function StatusAnfragen(int $variable): void
    {
        $instanz = IPS_GetParent($variable);
        if (IPS_InstanceExists($instanz) && function_exists('KNX_RequestStatus')) {
            @KNX_RequestStatus($instanz);
            IPS_Sleep(200);
        }
    }

    private function NachlaufStarten(): void
    {
        $nachlauf = $this->ReadPropertyInteger('Nachlauf');
        $this->SetTimerInterval('Nachlauf', ($nachlauf > 0 && $this->IstAn()) ? $nachlauf * 1000 : 0);
    }

    private function ZustandZeigen(): void
    {
        $an = $this->IstAn();
        $this->SetValue('Licht', $an);
        foreach ($this->Liste('LedsMdt') as $led) {
            @KNX_WriteDPT5($led['InstanceID'], $an ? self::LED_GRUEN : self::LED_ROT);
        }
        foreach ($this->Liste('LedsJung') as $led) {
            @KNX_WriteDPT1($led['RotID'], !$an);
            @KNX_WriteDPT1($led['GruenID'], $an);
        }
    }

    private function IstAn(): bool
    {
        foreach ($this->Liste('Lampen') as $lampe) {
            if (IPS_VariableExists($lampe['StatusID']) && GetValueBoolean($lampe['StatusID'])) {
                return true;
            }
        }
        return false;
    }

    private function KeinePraesenz(): bool
    {
        foreach ($this->Liste('Melder') as $melder) {
            if (GetValueBoolean($melder['VariableID'])) {
                return false;
            }
        }
        return true;
    }

    private function Liste(string $name): array
    {
        return json_decode($this->ReadPropertyString($name), true) ?: [];
    }
}
