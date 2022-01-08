<?php

declare(strict_types=1);

class FordPass {
    private $username;
    private $password;
    private $VIN;
    private $accessToken;
    private $refresToken; 
    private $expires;
    private $ExpiresIn;
    private $disableSSL;

    const BASE_ENDPOINT = 'https://usapi.cv.ford.com/api';
    const GUARD_ENDPOINT = 'https://api.mps.ford.com/api';
    const SSO_ENDPOINT = 'https://sso.ci.ford.com/oidc/endpoint/default/token';
    const OTA_ENDPOINT = 'https://www.digitalservices.ford.com/owner/api/v2/ota/status';

    const CLIENT_ID = '9fb503e0-715b-47e8-adfd-ad4b7770f73b';

    const DEFAULT_HEADERS = array(
                                "Accept:*/*",
                                "Accept-Language:en-us",
                                "User-Agent:fordpass-ap/93 CFNetwork/1197 Darwin/20.0.0",
                                "Accept-Encoding:gzip,deflate,br"
                            );

    const API_HEADERS = array(
                            "Content-Type:application/json"
                        );

    const OTA_HEADERS = array(
                            'Consumer-Key:Z28tbmEtZm9yZA==', 
                            'Referer:https://ford.com',
                            'Origin:https://ford.com'
                        );

    const AUTH_HEADERS = array(
                            "Content-Type:application/x-www-form-urlencoded"
                        );

    public function __construct(string $Region, String $Username='', string $Password='', string $AccessToken='', string $RefreshToken='', DateTime $Expires = null) {
        $this->username = $Username;
        $this->password = $Password;
        $this->Region = $Region;
        $this->accessToken =$AccessToken;
        $this->refreshToken = $RefreshToken;
        if($Expires==null)
            $this->expires = new DateTime('now');
        else
            $this->expires = $Expires;
        $this->disableSSL = false;
    }

    public function EnableSSLCheck() {
        $this->disableSSL = false;
    }

    public function DisableSSLCheck() {
        $this->disableSSL = true;
    }

    public function GetToken(){
        $token = array('AccessToken' => $this->accessToken);
        $token['RefreshToken'] = $this->refreshToken;
        $token['Expires'] = $this->expires;
        $token['ExpiresIn'] = $this->expiresIn;

        return (object)$token;
    }

    // Retrieve access token
    public function Connect() {
        if (strlen($this->accessToken)==0 || $this->expires < new DateTime('now')) {
            if(strlen($this->username)>0 && strlen($this->password)>0) {
                $url = self::SSO_ENDPOINT;
                $body = array('client_id' => self::CLIENT_ID);
                $body['grant_type'] = 'password';
                $body['username'] = $this->username; 
                $body['password'] = $this->password;
                
                
            } else {
                throw new Exception('Error: Missing username and/or password');
            }
        } else {
            // Use existing token
            return;
        }

        try {
            $now = new DateTime('now');
            
            $headers = array_merge(self::DEFAULT_HEADERS, self::AUTH_HEADERS);
            $result = $this->request('post', $url, $headers, http_build_query($body));
                        
            if($result->httpcode==200) {
                $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
                $body = array("code" => $result->result->access_token);
                $url = self::GUARD_ENDPOINT . '/oauth2/v1/token';
                
                $result = $this->request('put', $url, $headers, json_encode($body));
                
                if($result->httpcode==200) {
                    $this->accessToken = $result->result->access_token;
                    $this->refreshToken = $result->result->refresh_token; 
                    $this->expires = $now; 
                    $this->expires->add(new DateInterval('PT'.(string)$result->result->expires_in.'S')); // adds expiresIn to "now"
                    $this->expiresIn = $result->result->expires_in;
                } else {
                    if($result->error) {
                        throw new Exception(sprintf('%s failed (%d). The error was "%s"', $url, $result->httpcode, $result->errortext));
                    }
                
                    throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode));
                }
            } else {
                if($result->error) {
                    throw new Exception(sprintf('%s failed (%d). The error was "%s"', $url, $result->httpcode, $result->errortext));        
                }
                
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode));
            }
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
		}
    }

    // Refresh access token
    public function RefreshToken() {
        if(strlen($this->refreshToken)==0) {
            $this->Connect();
            return;
        }
        
        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $body = array('refresh_token' => $this->refreshToken); 
        $url = self::GUARD_ENDPOINT . '/oauth2/v1/refresh'; 
        
        try {
            $now = new DateTime('now');

            $result = $this->request('put', $url, $headers, json_encode($body));

            if($result->httpcode==200) {
                $this->accessToken = $result->result->access_token;
                $this->refreshToken = $result->result->refresh_token; 
                $this->expires = $now; 
                $this->expires->add(new DateInterval('PT'.(string)$result->result->expires_in.'S')); // adds expiresIn to "now"
                $this->expiresIn = $result->result->expires_in;
            } else if($result->httpcode==401){
                $this->Connect();
                return;
            } else {
                if($result->error) {
                    throw new Exception(sprintf('%s failed (%d). The error was "%s"', $url, $result->httpcode, $result->errortext));
                }
            
                throw new Exception(sprintf('%s returned http status code %d', $url, $result->httpcode));
            }
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function OTAInfo(string $VIN) {
        $this->Connect();

        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS, self::OTA_HEADERS);
        $url = self::OTA_ENDPOINT . '?vin='. $VIN;

        try {
            $result = $this->request('get', $url, $headers);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    // Get the status of the vehicle
    public function Status(string $VIN) {
        $this->Connect();
        
        $params = array("lrdt" => "01-01-1970 00:00:00");
        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::BASE_ENDPOINT . '/vehicles/v4/' . $VIN . '/status?' . http_build_query($params);
        
        try {
            $result = $this->request('get', $url, $headers);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    // Lock/unlock the vehicle
    public function Lock(string $VIN, bool $State) : bool {
        $this->Connect();
        
        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::BASE_ENDPOINT . '/vehicles/v2/' . $VIN . '/doors/lock';
        
        try {
            $result = $this->request($State?'put':'delete', $url, $headers);

           return $this->PollAndWaitForStatus($url, $headers, $result);

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    //  Start/stop the vehicle
    public function Start(string $VIN, bool $State) : bool {
        $this->Connect();

        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::BASE_ENDPOINT . '/vehicles/v2/' . $VIN . '/engine/start';
        
        try {
            $result = $this->request($State?'put':'delete', $url, $headers);

           return $this->PollAndWaitForStatus($url, $headers, $result);

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    // Enable/disable SecuriAlert
    public function Guard(string $VIN, bool $State) : bool {
        $this->Connect();

        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::GUARD_ENDPOINT . '/guardmode/v1/' . $VIN . '/session';
        
        try {
            $result = $this->request($State?'put':'delete', $url, $headers);

           //return $result;

           if($result->httpcode==200) {
                if(isset($result->result->returnCode)) {
                    if($result->result->returnCode==200) {
                        return true;
                    }

                    if($result->result->returnCode==303 && $State==false) {
                        return true;
                    }

                    if($result->result->returnCode==302 && $State==true) {
                        return true;
                    }

                    return false;

                } else {
                    return false;
                }
           } else {
               return false;
           }

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    // Request status on SecuriAlert
    public function GuardStatus(string $VIN) {
        $this->Connect();
        
        $params = array("lrdt" => "01-01-1970 00:00:00");
        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::GUARD_ENDPOINT . '/guardmode/v1/' . $VIN . '/session?' . http_build_query($params);
        
        try {
            $result = $this->request('get', $url, $headers);
            
            return $result;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    // Send a request to refresh data from the vehicles modules
    public function RequestUpdate(string $VIN) : bool {
        $this->Connect();

        $headers = array_merge(self::DEFAULT_HEADERS, self::API_HEADERS);
        $url = self::BASE_ENDPOINT . '/vehicles/v2/' . $VIN . '/status';
        
        try {
            $result = $this->request('put', $url, $headers);
            
            if(isset($result->result->status)) {
                return $result->result->status==200;
            } 

            return false;

        } catch(Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
        
    }

    private function PollAndWaitForStatus($Url, $Headers, $Result) : bool {
        if($Result->httpcode==200) {
            if(!isset($Result->result->commandId)) {
                throw new Exception(sprintf('%s failed. Invalid response. Missing value "commandId"', $Url));
            }

            if(strlen($Result->result->commandId)==0) {
                return false;
            }

            $Url .= '/'. $Result->result->commandId;                    
            
            for($count=1;$count<50;$count++) {
                $result = $this->request('get', $Url, $Headers);
                
                if(!isset($result->result->status)) {
                    throw new Exception(sprintf('%s failed. Invalid response. Missing value "status"', $Url));
                }
                if($result->result->status==200) {
                    return true;
                } else if($result->result->status==552) {
                    IPS_Sleep(1000);
                } else {
                    return false;
                }
            }
        }

        return false;

    }
       
    private function request($Type, $Url, $Headers, $Data=null) {
		$ch = curl_init();
		
		switch(strtolower($Type)) {
			case 'put':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				break;
			case 'post':
				curl_setopt($ch, CURLOPT_POST, true );
				break;
			case 'get':
                // Default for cURL
		    	break;
            case 'delete':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
		}

        if($Data!=null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $Data);
            $Headers[] = 'Content-Length:'. (string)strlen($Data);
        } else {
            $Headers[] = 'Content-Length:0';
        }

         $Headers[] = 'Application-Id:'. $this->Region;

        if(strlen($this->accessToken)>0 && $this->expires > new DateTime('now')) {
            $Headers[] = 'auth-token:'. $this->accessToken;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);

        if($this->disableSSL) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
		
		curl_setopt($ch, CURLOPT_URL, $Url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
	        		
		$result = curl_exec($ch);

        if($result===false) {
            $response = array('error' => true);
            $response['errortext'] = curl_error($ch);
            $response['httpcode'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            
            return (object) $response;
        } 

        $response = array('error' => false);
        $response['httpcode'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $response['result'] = (object) null ;
        
        $return = (object) $response;
        $return->result = $this->isJson($result)?json_decode($result):$result; 

        return  $return;
	}

    private function isJson(string $Data) {
        json_decode($Data);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
