<?php
/**
 * Nextcloud - ldapcontacts
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alexander Hornig <alexander@hornig-software.com>
 * @copyright Alexander Hornig 2017
 */

namespace OCA\LdapContacts\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCA\LdapContacts\Controller\SettingsController;
use OCA\User_LDAP\User\Manager;
use OCA\User_LDAP\Helper;
use OCA\User_LDAP\Mapping\UserMapping;
use OCA\User_LDAP\Mapping\GroupMapping;
use OCP\IDBConnection;

class ContactController extends Controller {
	// LDAP configuration
	protected $host;
	protected $port;
	protected $base_dn;
	protected $group_dn;
	protected $admin_dn;
	protected $admin_pwd;
	protected $user_filter;
	protected $user_filter_specific;
	protected $group_filter;
	protected $group_filter_specific;
	protected $ldap_version;
	protected $user_display_name;
	protected $group_display_name;
	protected $access;
	// ldap server connection
	protected $connection = false;
	// other variables
	protected $l;
	protected $config;
	protected $uid;
	protected $user_dn;
	protected $AppName;
    protected $settings;
	protected $db;
    // values
    protected $contacts_available_attributes;
    protected $contacts_default_attributes;
 	// all available statistics
 	protected $statistics = [ 'entries', 'entries_filled', 'entries_empty', 'entries_filled_percent', 'entries_empty_percent', 'users', 'users_filled_entries', 'users_empty_entries', 'users_filled_entries_percent', 'users_empty_entries_percent' ];

    /**
	 * @param string $AppName
	 * @param IRequest $request
	'entries', 'entries_filled', 'entries_empty', 'entries_filled_percent', 'entries_empty_percent', 'users', 'users_filled_entries', 'users_empty_entries', 'users_filled_entries_percent', 'users_empty_entries_percent' ];  * @param IConfig $config
	 * @param SettingsController $settings
     * @param mixed $UserId
	 * @param Manager $userManager
	 * @param Helper $helper
	 * @param UserMapping $userMapping
	 * @param GroupMapping $groupMapping
	 */
	public function __construct( $AppName, IRequest $request, IConfig $config, SettingsController $settings, $UserId, Manager $userManager, Helper $helper, UserMapping $userMapping, GroupMapping $groupMapping, IDBConnection $db ) {
		// check we have a logged in user
		\OCP\User::checkLoggedIn();
		parent::__construct( $AppName, $request );
		// get database connection
		$this->db = $db;
        // get the settings controller
        $this->settings = $settings;
		// get the config module for user settings
		$this->config = $config;
		// save the apps name
		$this->AppName = $AppName;
		// get the current users id
		$this->uid = $UserId;
		// load ldap configuration from the user_ldap app
		$this->load_config( $userManager, $helper, $userMapping, $groupMapping );
		// connect to the ldap server
		$this->connection = ldap_connect( $this->host, $this->port );
		
		// TODO(hornigal): catch ldap errors
		ldap_set_option( $this->connection, LDAP_OPT_PROTOCOL_VERSION, $this->ldap_version);
		ldap_bind( $this->connection, $this->admin_dn, $this->admin_pwd );
		
		// load translation files
		$this->l = \OC::$server->getL10N( 'ldapcontacts' );
        
        // define ldap attributes
        $this->contacts_available_attributes = $this->settings->getSetting( 'user_ldap_attributes', false );
		
		// set the ldap attributes that are filled out by default
		$this->contacts_default_attributes = [ $this->settings->getSetting( 'login_attribute', false ), 'givenname', 'sn' ];
	}
	
	/**
	 * loads the ldap configuration from the user_ldap app
	 * 
	 * @param string $prefix
	 */
	private function load_config( Manager $userManager, Helper $helper, UserMapping $userMapping, GroupMapping $groupMapping, $prefix = '' ) {
		// load configuration
		$ldapWrapper = new \OCA\User_LDAP\LDAP();
		$connection = new \OCA\User_LDAP\Connection( $ldapWrapper );
		$config = $connection->getConfiguration();
		// check if this is the correct server or if we have to use a prefix
		if( empty( $config['ldap_host'] ) ) {
			$connection = new \OCA\User_LDAP\Connection( $ldapWrapper, 's01' );
			$config = $connection->getConfiguration();
		}
		
		// get the users dn
		$this->access = new \OCA\User_LDAP\Access( $connection, $ldapWrapper, $userManager, $helper );
		$this->access->setUserMapper( $userMapping );
		$this->access->setGroupMapper( $groupMapping );
		
		// put the needed configuration in the local variables
		$this->host = $config['ldap_host'];
		$this->port = $config['ldap_port'];
		$this->base_dn = $config['ldap_base'];
		$this->user_dn = $config['ldap_base_users'];
		$this->group_dn = $config['ldap_base_groups'];
		$this->admin_dn = $config['ldap_dn'];
		$this->admin_pwd = $config['ldap_agent_password'];
		$this->user_filter =  $config['ldap_userlist_filter'];
		$this->user_filter_specific = $config['ldap_login_filter'];
		$this->group_filter = $config['ldap_group_filter'];
		$this->group_filter_specific = '(&' . $config['ldap_group_filter'] . '(gidNumber=%gid))';
		$this->ldap_version = 3;
		$this->user_display_name = $config['ldap_display_name'];
		$this->group_display_name = $config['ldap_group_display_name'];
	}
	
	/**
	 * returns the main template
	 * 
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index() {
        $params = [];
        // get the users possible ldap attributes
        if( $user_ldap_attributes = $this->settings->getSetting( 'user_ldap_attributes', false ) ) {
            $params['user_ldap_attributes'] = $user_ldap_attributes;
        }
        // get the users login attribute
        if( $login_attribute = $this->settings->getSetting( 'login_attribute', false ) ) {
            $params['login_attribute'] = $login_attribute;
        }
        
        // return the main template
		return new TemplateResponse( 'ldapcontacts', 'main', $params );
	}

	/**
	 * get all users
	 *
	 * @NoAdminRequired
	 */
	public function load() {
		return new DataResponse( $this->getUsers() );
	}
	
	/**
	* shows a users own data
	* 
	* @NoAdminRequired
	*/
	public function show() {
		// get the users info
		return new DataResponse( $this->getUsers( $this->uid ) );
	}
	
	/**
	* shows all available groups
	* 
	* @NoAdminRequired
	*/
	public function groups() {
		return new DataResponse( $this->getGroups() );
	}
	
	/**
	* updates a users own data
	* 
	* @NoAdminRequired
	*
	* @param string $data		jQuery parsed form
	*/
	public function update( $data ) {
		// parse given data
		parse_str( urldecode( $data ), $array );

		$modify = [];
		foreach( $array['user_ldap_attributes'] as $attribute => $value ) {
			$value = trim( $value );
			$attribute = str_replace( "'", "", $attribute );
			
			// remove, add or modify attribute
			$modify[ $attribute ] = $value === '' ? [] : $value;
		}
		
		// get own dn
		if( !$dn = $this->get_own_dn() ) return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Something went wrong while saving your data' ) ), 'status' => 'error' ) );
		
		// update given values
		if( ldap_modify( $this->connection, $dn, $modify ) ) return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Your data has successfully been saved' ) ), 'status' => 'success' ) );
		else return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Something went wrong while saving your data' ) ), 'status' => 'error' ) );
	}
	
	/**
	 * get all users from the LDAP server
	 * 
	 * @NoAdminRequired
	 * 
	 * @param string $uid
	 */
	protected function getUsers( $uid=false ) {
		$entry_id_attribute = $this->settings->getSetting( 'entry_id_attribute', false );
		$user_group_id_attribute = $this->settings->getSetting( 'user_group_id_attribute', false );
		
		// get a specific user filter if a specific user is requested
		if( $uid ) {
			// get the users dn
			$dn = $this->access->username2dn( $uid );
			// if no user was found, abort
			if( !$dn ) return false;
			$entry_id = $this->getEntryLdapId( $dn );
			$user_filter = '(&' . $this->user_filter . '(' . $entry_id_attribute . '=' . ldap_escape( $entry_id ) . '))';
		}
		else $user_filter = $this->user_filter;
		
		$request = ldap_search( $this->connection, $this->user_dn, $user_filter, [ '*', $entry_id_attribute ] );
		
		// if no user was found, abort
		if( is_bool( $request ) ) return false;
		
		$results = ldap_get_entries( $this->connection, $request );
		
		unset( $results['count'] );
		$return = array();
		$ldap_attributes = array_merge( $this->settings->getSetting( 'user_ldap_attributes', false ), [ $this->user_display_name => '', $user_group_id_attribute => '' ] );
		
		// get all hidden users
		$hidden = $this->adminGetEntryHidden( 'user', false );
		
		foreach( $results as $i => $result ) {
			// only hide the user if it isn't requested directly
			if( !$uid || !isset( $dn ) ) {
				// check that the user is not hidden
				$is_hidden = false;
				foreach( $hidden as $user ) {
					if( $result[ $entry_id_attribute ] == $user[ $entry_id_attribute ] ) {
						$is_hidden = true;
						break;
					}
				}
				if( $is_hidden ) continue;
			}
			
			$tmp = array();
			foreach( $ldap_attributes as $attribute => $value ) {
				// check if the value exists for the user
				if( isset( $result[ $attribute ] ) ) {
					if( is_array( $result[ $attribute ] ) )
						$tmp[ $attribute ] = trim( $result[ $attribute ][0] );
					else
						$tmp[ $attribute ] = trim( $result[ $attribute ] );
				}
			}
			
			// add the entrys id
			if( !isset( $result[ $entry_id_attribute ] ) || empty( $result[ $entry_id_attribute ] ) ) continue;
			$tmp['ldapcontacts_entry_id'] = is_array( $result[ $entry_id_attribute ] ) ? $result[ $entry_id_attribute ][0] : $result[ $entry_id_attribute ];
			
			// a contact has to have a name
			// TODO: check if it might be useful to put a placeholder here if no name is given
			if( !isset( $result[ $this->user_display_name ] ) || empty( trim( $result[ $this->user_display_name ] ) ) ) continue;
			$tmp['ldapcontacts_name'] = $result[ $this->user_display_name ];
			
			// get the users groups
			$groups = $this->getGroups( $result[ $user_group_id_attribute ] );
			if( $groups ) $tmp['groups'] = $groups;
			else $tmp['groups'] = array();
			
			// delete all empty entries
			foreach( $tmp as $key => $value ) {
				if( !is_array( $value ) && empty( trim( $value ) ) ) unset( $tmp[ $key ] );
			}
			
			array_push( $return, $tmp );
		}
		
		// order the users
		usort( $return, [ $this, 'order_ldap_contacts' ] );
		
		return $return;
	}
				  
	/**
	 * orders the given user array by the ldap attribute selected by the user
	 * 
	 * @param array $a
	 * @param array $b
	 */
	protected function order_ldap_contacts( $a, $b ) {
		$order_by = $this->config->getUserValue( $this->uid, $this->AppName, 'order_by' );
		// check if the arrays can be compared
		if( !isset( $a[ $order_by ], $b[ $order_by ] ) ) return 1;
		// compare
		return $a[ $order_by ] <=> $b[ $order_by ];
	}
	
	/**
	 * returns an array of all existing groups or all groups the given user is a member of
	 * 
	 * @param string $user_group_id		the id of a user, whos groups should be found
	 */
	protected function getGroups( $user_group_id=false ) {
		// construct the filter
		$user_group_id_group_attribute = $this->settings->getSetting( 'user_group_id_group_attribute', false );
		$entry_id_attribute = $this->settings->getSetting( 'entry_id_attribute', false );
		$attributes = [ '*', $entry_id_attribute, $this->group_display_name ];
		
		// if the groups of a given user should be found, use a specific filter
		if( $user_group_id ) $filter = '(&' . $this->group_filter . '(' . $user_group_id_group_attribute . '=' . $user_group_id . '))';
		// use the general filter
		else $filter = $this->group_filter;
		
		// fetch all groups from the ldap server
		$request = ldap_list( $this->connection, $this->group_dn, $filter, $attributes );
		$groups = ldap_get_entries( $this->connection, $request );
		
		// check if request was successful and if so, remove the count variable
		if( $groups['count'] < 1 ) return [];
		array_shift( $groups );
		
		// get all hidden groups
		$hidden = $this->adminGetEntryHidden( 'group', false );
		
		// go through all the groups
		foreach( $groups as $id => &$group ) {
			// if the groups isn't requested specifically, see if it is hidden
			// only hide the user if it isn't requested directly
			if( !$user_group_id ) {
				// check that the user is not hidden
				$is_hidden = false;
				foreach( $hidden as $tmp ) {
					if( $group[ $entry_id_attribute ] == $tmp[ $entry_id_attribute ] ) {
						$is_hidden = true;
						break;
					}
				}
				if( $is_hidden ) {
					unset( $groups[ $id ] );
					continue;
				}
			}
			
			
			// add the groups name
			$group['ldapcontacts_name'] = is_array( $group[ $this->group_display_name ] ) ? $group[ $this->group_display_name ][0] : $group[ $this->group_display_name ];
			// add the groups id
			$group['ldapcontacts_entry_id'] = is_array( $group[ $entry_id_attribute ] ) ? $group[ $entry_id_attribute ][0] : $group[ $entry_id_attribute ];
		}
		
		// order the groups
		usort( $groups, function( $a, $b ) {
			return $a['ldapcontacts_name'] <=> $b['ldapcontacts_name'];
		});
		
		// return the groups
		return $groups;
	}
	
	/**
	 * gets the user username (used for identification in groups)
	 * 
	 * @param $uid		the users id
	 */
	protected function get_uname( $uid ) {
		// get the users dn
		$dn = $this->access->username2dn( $uid );
		// run a query with the found dn
		$request = ldap_search( $this->connection, $dn, '(objectClass=*)', array( $this->settings->getSetting( 'user_group_id_attribute', false ) ) );
		
		$entries = ldap_get_entries($this->connection, $request);
		// check if request was successful
		if( $entries['count'] < 1 ) return false;
		else return $entries[0][ $this->settings->getSetting( 'login_attribute', false ) ][0];
	}
	
	/**
	 * get the users own dn
	 */
	protected function get_own_dn() {
		// check this user actually has a uid
		if( empty( $this->uid ) ) return false;
		// get the users dn
		return $this->access->username2dn( $this->uid );
	}
	
	/**
	 * hides the given entry
	 * 
	 * @param string entry_id
	 */
	public function adminHideEntry( $entry_id, $type ) {
		// check if the user is already hidden
		if( $this->userHidden( $entry_id ) ) return true;
		
		// hide the user
		$sql = "INSERT INTO *PREFIX*ldapcontacts_hidden_entries SET entry_id = ?, type = ?";
		$stmt = $this->db->prepare( $sql );
		$stmt->bindParam( 1, $entry_id, \PDO::PARAM_STR );
		$stmt->bindParam( 2, $type, \PDO::PARAM_STR );
		$stmt->execute();
		
		// check for sql errors
		if( $stmt->errorCode() == '00000' ) return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Entry is now hidden' ) ), 'status' => 'success' ) );
		else return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Making entry invisible failed' ) ), 'status' => 'error' ) );
	}
	
	/**
	 * get an ldap entrys unique id
	 * 
	 * @param string $dn
	 */
	protected function getEntryLdapId( string $dn ) {
		$entry_id_attribute = $this->settings->getSetting( 'entry_id_attribute', false );
		// fetch the entrys info from the ldap server
		$request = ldap_search( $this->connection, $dn, '(objectClass=*)', array( $entry_id_attribute ) );
		$results = ldap_get_entries( $this->connection, $request );
		// check if an entry was found
		if( $results['count'] == 0 ) return false;
		
		// get the entry id from the ldap info
		if( is_array( $results[0][ $entry_id_attribute ] ) ) $entry_id = $results[0][ $entry_id_attribute ][0];
		else $entry_id = $results[0][ $entry_id_attribute ];
		
		return $entry_id;
	}
	
	/**
	 * checks if the given user is already hiden
	 * 
	 * @param string $user_id
	 * 
	 * @return bool		wether the user is hidden or not
	 */
	private function userHidden( $user_id ) {
		// get all hidden users
		$hidden = $this->adminGetEntryHidden( 'user', false );
		// check if the given user is one of them
		return in_array( $user_id, $hidden );
	}
	
	/**
	 * shows the given LDAP entry
	 * 
	 * @param string entry_id
	 */
	public function adminShowEntry( $entry_id ) {
		$sql = "DELETE FROM *PREFIX*ldapcontacts_hidden_entries WHERE entry_id = ?";
		$stmt = $this->db->prepare( $sql );
		$stmt->bindParam( 1, $entry_id, \PDO::PARAM_STR );
		$stmt->execute();
		
		// check for sql errors
		if( $stmt->errorCode() == '00000' ) return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Entry is now visible again' ) ), 'status' => 'success' ) );
		else return new DataResponse( array( 'data' => array( 'message' => $this->l->t( 'Making entry visible failed' ) ), 'status' => 'error' ) );
	}
	
	/**
	 * gets all entries of the given type that are hidden
	 * 
	 * @param string $type
	 * @param bool $DataResponse
	 */
	public function adminGetEntryHidden( string $type, bool $DataResponse=true ) {
		$sql = "SELECT entry_id FROM *PREFIX*ldapcontacts_hidden_entries WHERE type = ?";
		$stmt = $this->db->prepare( $sql );
		$stmt->bindParam( 1, $type, \PDO::PARAM_STR );
		$stmt->execute();
		
		// check for sql errors
		if( $stmt->errorCode() != '00000' ) {
			if( $DataResponse ) return new DataResponse( array( 'data' => array( 'message' => $this->l->t( "Hidden entries couldn't be loaded" ) ), 'status' => 'error' ) );
			else return false;
		}
		
		// get all hidden entries
		$tmp = [];
		while( $hidden = $stmt->fetchColumn() ) {
			array_push( $tmp, $hidden );
		}
		$stmt->closeCursor();
		
		// get additional data for each entry
		$entries = [];
		foreach( $tmp as $entry ) {
			array_push( $entries, $this->getLdapEntryById( $entry, $type ) );
		}
		
		// return fetched entries
		if( $DataResponse ) return new DataResponse( array( 'data' => $entries, 'status' => 'success' ) );
		else return $entries;
	}
	
	/**
	 * gets data
	 * 
	 * @param string $entry_id
	 * @param string $type
	 */
	protected function getLdapEntryById( string $entry_id, string $type='' ) {
		$entry_id_attribute = $this->settings->getSetting( 'entry_id_attribute', false );
		$request = ldap_search( $this->connection, $this->base_dn, '(' . $entry_id_attribute . '=' . ldap_escape( $entry_id ) . ')', [ '*', $entry_id_attribute ] );
		$entry = ldap_get_entries( $this->connection, $request )[0];
		
		// add the entry id
		$entry[ 'ldapcontacts_entry_id' ] = $entry_id;
		
		// add the entrys name
		switch( $type ) {
			case 'user':
				$name = $entry[ $this->user_display_name ];
				$entry['ldapcontacts_name'] = is_array( $name ) ? $name[0] : $name;
				break;
			case 'group':
				$name = $entry[ $this->group_display_name ];
				$entry['ldapcontacts_name'] = is_array( $name ) ? $name[0] : $name;
				break;
		}
		
		return $entry;
	}
    
    /**
     * get all available statistics
     */
    public function getStatistics() {
        // get them all
        $data = [ 'status' => 'success' ];
        foreach( $this->statistics as $type ) {
            // get the statistic
            $stat = $this->getStatistic( $type )->getData();
            // check if something went wrong
            if( $stat['status'] !== 'success' ) {
                return new DataResponse( [ 'status' => 'error' ] );
            }
            // add the data to the bundle
            $data[ $type ] = $stat['data'];
        }
        
        // return collected statistics
        return new DataResponse( $data );
    }
    
    /**
     * computes the wanted statistic
     * 
     * @param string $type      the type of statistic to be returned
     */
    public function getStatistic( $type ) {
        switch( $type ) {
            case 'entries':
                $data = $this->entryAmount();
                break;
            case 'entries_filled':
                $data = $this->entriesFilled();
                break;
            case 'entries_empty':
                $data = $this->entriesEmpty();
                break;
            case 'entries_filled_percent':
                $data = $this->entriesFilledPercent();
                break;
            case 'entries_empty_percent':
                $data = $this->entriesEmptyPercent();
                break;
            case 'users':
                $data = $this->userAmount();
                break;
            case 'users_filled_entries':
                $data = $this->usersFilledEntries();
                break;
            case 'users_empty_entries':
                $data = $this->usersEmtpyEntries();
                break;
            case 'users_filled_entries_percent':
                $data = $this->usersFilledEntriesPercent();
                break;
            case 'users_empty_entries_percent':
                $data = $this->usersEmptyEntriesPercent();
                break;
            default:
                // no valid statistic given
                return new DataResponse( [ 'status' => 'error' ] );
        }
        // return gathered data
        return new DataResponse( [ 'data' => $data, 'status' => 'success' ] );
    }
    
    /**
     * get all user attributes that aren't filled from the start
     */
    protected function userNonDefaultAttributes() {
        // get all user attributes
        $attributes = $this->contacts_available_attributes;
        // remove all defaults
        foreach( $this->contacts_default_attributes as $key ) {
            unset( $attributes[ $key ] );
        }
        // return non default attributes
        return $attributes;
    }
    
    /**
     * amount of entries users can edit
     */
    protected function entryAmount() {
        // get all attributes the users can edit
        $attributes = $this->userNonDefaultAttributes();
        // get all users and their data
        $users = $this->getUsers();
        // init counter
        $amount = 0;
        
        // count the entries
        foreach( $users as $user ) {
            foreach( $attributes as $attr ) {
                $amount++;
            }
        }
        
        // return the counted amount
        return $amount;
    }
    
    /**
     * amount of entries the users have filled out
     */
    protected function entriesFilled() {
        // get all attributes the users can edit
        $attributes = $this->userNonDefaultAttributes();
        // get all users and their data
        $users = $this->getUsers();
        // init counter
        $amount = 0;
        
        // count the entries
        foreach( $users as $user ) {
            foreach( $attributes as $attr => $v ) {
                // check if the entry is filled
                if( !empty( $user[ $attr ] ) ) {
                    $amount++;
                }
            }
        }
        
        // return the counted amount
        return $amount;
    }
    
    /**
     * amount of entries the users haven't filled out
     */
    protected function entriesEmpty() {
        return $this->entryAmount() - $this->entriesFilled();
    }
    
    /**
     * amount of entries the users have filled out, in percent
     */
    protected function entriesFilledPercent() {
        $amount = $this->entryAmount();
        return $amount > 0 ? round( $this->entriesFilled() / $amount * 100, 2 ) : 0;
    }
    
    /**
     * amount of entries the users haven't filled out, in percent
     */
    protected function entriesEmptyPercent() {
        $amount = $this->entryAmount();
        return $amount > 0 ? round( $this->entriesEmpty() / $amount * 100, 2 ) : 0;
    }
    
    /**
     * amount of registered users
     */
    protected function userAmount() {
        return count( $this->getUsers() );
    }
    
    /**
     * how many users have filled at least one of their entries
     */
    protected function usersFilledEntries() {
        // get all attributes the users can edit
        $attributes = $this->userNonDefaultAttributes();
        // get all users and their data
        $users = $this->getUsers();
        // init counter
        $amount = 0;
        
        // count the entries
        foreach( $users as $user ) {
            foreach( $attributes as $attr => $v ) {
                // check if the entry is filled
                if( !empty( $user[ $attr ] ) ) {
                    $amount++;
                    break;
                }
            }
        }
        
        // return the counted amount
        return $amount;
    }
    
    /**
     * how many users have filled none of their entries
     */
    protected function usersEmtpyEntries() {
        return $this->userAmount() - $this->usersFilledEntries();
    }
    
    /**
     * how many users have filled at least one of their entries, in percent
     */
    protected function usersFilledEntriesPercent() {
        $amount = $this->userAmount();
        return $amount > 0 ? round( $this->usersFilledEntries() / $amount * 100, 2 ) : 0;
    }
    
    /**
     * how many users have filled none of their entries, in percent
     */
    protected function usersEmptyEntriesPercent() {
        $amount = $this->userAmount();
        return $amount > 0 ? round( $this->usersEmtpyEntries() / $amount * 100, 2 ) : 0;
    }
}
