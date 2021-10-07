<?php

declare(strict_types=1);
	class FordpassGateway extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

		$this->RegisterPropertyString('Username', '');
		$this->RegisterPropertyString('Password', '');
		$this->RegisterPropertyString('VIN', '');
		$this->RegisterPropertyString('Region', 'UK&Europe');

		$this->RegisterPropertyBoolean('SkipSSLCheck', true);

		$this->RegisterTimer('FordPassRefreshToken' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "RefreshToken", 0);'); 

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();

			if (IPS_GetKernelRunlevel() == KR_READY) {
				$this->InitFordPass();
			}
		}

		public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
			parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
	
			if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
				$this->InitFordPass();
			}
		}

		private function InitFordPass() {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Initializing the Easee Class...', 0);
	
			$this->SetTimerInterval('FordPassRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer
	
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
	
			if(strlen($username)==0) {
				$this->LogMessage(sprintf('InitFordPass(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('InitFordPass(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), 0);
				
				return null;
			}
	
			$easee = new FordPass($username, $password);
			
			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$easee->DisableSSLCheck();
			}
			
			try {
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Connecting to Easee Cloud API...', 0);
				$easee->Connect();
				$token = $easee->GetToken();
				
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Saving Token for later use: %s', json_encode($token)), 0);
				$this->AddTokenToBuffer($token);
				
				$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout
	
				$this->SetTimerInterval('EaseeHomeRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);
			} catch(Exception $e) {
				$this->LogMessage(sprintf('Failed to connect to Easee Cloud API. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Failed to connec to Easee Cloud API. The error was "%s"', $e->getMessage()), 0);
				return null;
			}
	
			return $easee;
		}

		private function GetTokenFromBuffer() {
			if($this->Lock('Token')) {
				$jsonToken = $this->GetBuffer('Token');
				
				if(strlen($jsonToken)==0) {
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Missing token in the buffer', $jsonToken), 0);
					$this->Unlock('Token');
					return null;
				}
	
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got token "%s" from the buffer', $jsonToken), 0);
				$this->Unlock('Token');
				
				$token = json_decode($jsonToken);
				$expires = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
				$token->Expires = $expires; 
				
				return $token;
			} 
	
			return null;
		}
	
		private function AddTokenToBuffer($Token) {
			if($this->Lock('Token')) {
				if($Token==null)
					$token = '';
				else
					$token = json_encode($Token);
				$this->SetBuffer('Token', $token);
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Added token "%s" to the buffer', $token), 0);
				$this->Unlock('Token');
			}
		}
	
		private function Lock(string $Id) : bool {
			for ($i=0;$i<500;$i++){
				if (IPS_SemaphoreEnter("FordPass" . (string)$this->InstanceID . $Id, 1)){
					if($i==0) {
						$msg = sprintf('Created the Lock with id "%s"', $Id);
					} else {
						$msg = sprintf('Released and recreated the Lock with id "%s"', $Id);
					}
					$this->SendDebug(IPS_GetName($this->InstanceID), $msg, 0);
					return true;
				} else {
					if($i==0) {
						$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Waiting for the Lock with id "%s" to be released', $Id), 0);
					}
					IPS_Sleep(mt_rand(1, 5));
				}
			}
			
			$this->LogMessage(sprintf('Timedout waiting for the Lock with id "%s" to be released', $Id), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Timedout waiting for the Lock with id "%s" to be released', $Id), 0);
			
			return false;
		}
	
		private function Unlock(string $Id)
		{
			IPS_SemaphoreLeave("FordPass" . (string)$this->InstanceID . $Id);
	
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Removed the Lock with id "%s"', $Id), 0);
		}
	}