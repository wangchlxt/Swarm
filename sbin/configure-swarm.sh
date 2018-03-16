#!/bin/bash
ME="configure-swarm.sh"
#-------------------------------------------------------------------------------
# Configuration script for Helix Swarm
# Copyright 2013-2016 Perforce Software, Inc. All rights reserved.
#-------------------------------------------------------------------------------


usage()
{
    cat << __USAGE__
Usage:
    $ME [-f]
    $ME [-f] [-p p4port] [-u swarm-user] [-w swarm-passwd] \\
    ${ME//?/ } -e email-host [-H swarm-host]
    $ME [-f] -p p4port -u swarm-user -w swarm-passwd \\
    ${ME//?/ } -e email-host [-H swarm-host]
    $ME -c [-g]
    $ME -p p4port -u swarm-user -w swarm-passwd \\
    ${ME//?/ } -e email-host [-H swarm-host] \\
    ${ME//?/ } -c -g -U super-user -W super-passwd

    -p|--p4port <P4PORT>        -Helix Versioning Engine address
                                 (defaults to P4PORT from environment)
    -u|--swarm-user <username>  -Helix user name for Swarm
    -w|--swarm-passwd <passwd>  -Swarm user password or login ticket
    -H|--swarm-host <hostname>  -Hostname for this Swarm server
                                 (defaults to [$DEFAULT_SWARM_HOST])
    -P|--swarm-port             -TCP port for this Swarm server
                                 (defaults to HTTP, port 80)
    -B|--base-url <prefix>      -Optional Base URL for this Swarm server
                                 Example: -B /swarm
                                 (manual virtual host configuration is
                                 required if set)
    -e|--email-host <hostname>  -Mail relay host

    -f|--force                  -Do not check p4port nor swarm creds
                                 (not applicable if creating creds)

    -n|--non-interactive        -Enable non-interactive mode
                                 Script will exit with an error if
                                 required fields are omitted

    -c|--create                 -Create the Swarm user
    -g|--create-group           -Create a long-lived ticket group for the Swarm user
    -U|--super-user <username>  -Helix super-user login name
    -W|--super-passwd <passwd>  -Helix super-user password or login ticket

    -h|--help                   -Display usage, plus examples and notes

    "Helix Versioning Engine" can refer to a Helix server (p4d), broker, replica,
    edge server, or commit server.

__USAGE__
    [[ "$1" == "exit" ]] && exit 1
}
usage_error()
{
    [[ -n "$1" ]] && echo "$ME: $*"
    usage exit
}
usage_help()
{
    usage
    cat << __HELP__
Examples:

    Prompt user for information needed to configure Swarm, including the p4port,
    Swarm user & password, email host and Swarm host; entered values will be
    validated:
    $ $ME

    Like above, but skip any validation checks. This is useful if the Helix
    Versioning Engine is not currently available, but you know the values are
    correct:
    $ $ME -f

    Like the first example, but prompt for super user credentials to create the
    Swarm user using values obtained interactively.
    $ $ME -c

Non-Interactive Examples:

    Configure using existing swarm/swarmpw credentials on p4server:1666, without
    prompting user for any values. Note that in this case, the Swarm user
    credentials must be valid, or configuration will abort:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com

    Like above, but also set Swarm host name; note that we do not verify if
    the host specified will actually reach this host:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com -H swarm.company.com

    Like above, but avoid any validity checks:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com -H swarm.company.com -f

    Configure using a Base URL; note that this requires manual configuration
    of your existing Apache installation - instructions are included in the output:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com -H swarm.company.com -B /swarm

    Configure, passing Helix Versioning Engine super user credentials; note that
    if the Swarm user already exists, but the password is incorrect,
    configuration will abort:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com -c -U super -W superpw

    Like above, but skip any validity checks (beyond the super user credentials
    needing to be valid); this means that if the Swarm user already exists, but
    the password is different, the password will be reset:
    $ $ME -n -p p4host:1666 -u swarm -w swarmpw -e mx.example.com -c -U super -W superpw -f

Notes:

* This script needs to be run as root.

* If a Helix password specified looks like a login ticket (32 hexdecimal
  characters), it will be assumed to be as such.

* If using a login ticket instead of a password for the Swarm user, please
  ensure the user belongs to a group with an appropriately long timeout value.

__HELP__
    exit 1
}

#-------------------------------------------------------------------------------
# Global variables
#-------------------------------------------------------------------------------

# Perforce installation root
P4_INSTALL_ROOT="/opt/perforce"

# Perforce configuration directory
P4_CONFIG_DIR="${P4_INSTALL_ROOT}/etc"

# Where we install Swarm
SWARM_DIR="${P4_INSTALL_ROOT}/swarm"

# Where we log the output of this script
LOG="${SWARM_DIR}/data/${ME%.sh}.log"

# Swarm config file
SWARM_CONFIG="${SWARM_DIR}/data/config.php"

# Default Swarm username
DEFAULT_SWARM_USER="swarm"

# Holder to track pre-existance of swarm user in Perforce
USER_EXIST=

# Default Swarm host
DEFAULT_SWARM_HOST="$(hostname -f)"

if [ -z "$DEFAULT_SWARM_HOST" ]; then
    DEFAULT_SWARM_HOST="$(hostname)"
fi

# Location of P4 binary
P4_BINDIR="${P4_INSTALL_ROOT}/bin"

# Set up php environment
PHP_VERSION="$(php -v 2> /dev/null | sed -e '/^PHP/s/PHP \([0-9]\)\.\([0-9][0-9]*\).*/\1\2/;q')"
case "${PHP_VERSION}" in
    5*)
        PHP_INI_DIR_RHEL="/etc/php.d"
        PHP_INI_DIR_UBUNTU="/etc/php5/apache2/conf.d"
        RHEL_APACHE_PHP_MODULE="php5_module"
        UBUNTU_APACHE_PHP_MODULE="php5"
        ;;
    7*)
        PHP_INI_DIR_RHEL="/etc/php.d"
        PHP_INI_DIR_UBUNTU="/etc/php/7.0/apache2/conf.d"
        RHEL_APACHE_PHP_MODULE="php7_module"
        UBUNTU_APACHE_PHP_MODULE="php7.0"
        ;;
    *)
        echo "PHP Version ${PHP_VERSION-Unknown} is not supported, please install 5.* or 7.*"
        exit 1
        ;;
esac

# Apache user/group info
APACHE_USER_RHEL="apache"
APACHE_GROUP_RHEL="apache"
APACHE_USER_UBUNTU="www-data"
APACHE_GROUP_UBUNTU="www-data"

# Apache log directories
APACHE_LOG_DIR_RHEL="/var/log/httpd"
APACHE_LOG_DIR_UBUNTU="/var/log/apache2"

# Apache configuration files for setting Listen directives
APACHE_PORT_CONF_RHEL="/etc/httpd/conf/httpd.conf"
APACHE_PORT_CONF_UBUNTU="/etc/apache2/ports.conf"

# Virtual host config file
VHOST_CONFIG_SRC="${P4_CONFIG_DIR}/perforce-swarm-site.conf"
VHOST_CONFIG_RHEL="/etc/httpd/conf.d/perforce-swarm-site.conf"
VHOST_CONFIG_UBUNTU="/etc/apache2/sites-available/perforce-swarm-site.conf"

# Cron configuration file
CRON_CONFIG="${P4_CONFIG_DIR}/swarm-cron-hosts.conf"

# Holder of any warnings to report during configuration
WARNINGS=

#-------------------------------------------------------------------------------
# Functions
#-------------------------------------------------------------------------------

# Abort
die()
{
    echo -e "\n$ME: error: $@"
    exit 1
}

# Display warning and save it for summary at the end
warn()
{
    local msg="$1"
    [[ -z "$msg" ]] && die "no message passed to warn()"

    echo -e "\n>>>> WARNING <<<<\n$msg\n"
    WARNINGS="${WARNINGS}
${msg}
"

    return 0
}

# Prompt the user for information by showing a prompt string
# If the prompt is for a password, disable echo on the terminal.
# Optionally call validation function to check if the response is OK.
#
# promptfor <VAR> <prompt> [<ispassword>] [<defaultvalue>] [<validationfunc>]
promptfor()
{
    local secure=false
    local default_value=""
    local check_func=true

    local var="$1"
    local prompt="$2"
    [[ -n "$3" ]] && secure="$3"
    [[ -n "$4" ]] && default_value="$4"
    [[ -n "$5" ]] && check_func="$5"

    [[ -n "$default_value" ]] && prompt="$prompt [$default_value]"
    $secure && prompt="$prompt (typing hidden)"

    while true; do
        local resp=""
        $secure && stty -echo echonl

        read -p "$prompt: " resp
        stty echo -echonl

        if [[ -z "$resp" && -n "$default_value" ]]; then
            resp="$default_value"
            echo "-using default value [$resp]"
        else
            ! $secure && echo "-response: [$resp]"
        fi

        if $check_func "$resp"; then
            eval "$var='$resp'"
            break;
        else
            echo "-please try again"
        fi
    done
    echo ""
}

# prompt_yn "Do you wish to do thing?" "y" -> 0 for yes, 1 for no
prompt_yn()
{
    local prompt="$1"
    local default_value="${2:-y}"
    while true; do
        read -p "$prompt (y/n) [$default_value] " yn
        yn="${yn:-$default_value}"
        case $yn in
            [Yy]* ) return 0;;
            [Nn]* ) return 1;;
            * ) echo "Please answer yes or no.";;
        esac
    done
}

validate_nonempty()
{
    [[ ! -n "$1" ]] && echo "-empty response given" && return 1
    return 0
}

validate_ticket()
{
    [[ ! -n "$1" ]] && echo "-empty response given" && return 1
    [[ ! "$1" =~ ^[0-9A-F]{32}$ ]] &&
        echo "-response does not look like a ticket value (32 hex characters)" &&
        return 1
    return 0
}

validate_noticket()
{
    [[ ! -n "$1" ]] && echo "-empty response given" && return 1
    [[ "$1" =~ ^[0-9A-F]{32}$ ]] &&
        echo "-response looks like a ticket value" &&
        return 1
    return 0
}

validate_username()
{
    local badchars='[ @#]'
    local allnums='^[0-9]*$'
    [[ -z "$1" ]] && echo "-empty response given" && return 1
    [[ "$1" =~ ^- ]] && echo "-username cannot start with '-'" && return 1
    [[ "$1" =~ \.\.\. ]] && echo "-username cannot contain '...'" && return 1
    [[ "$1" =~ $badchars ]] && echo "-username cannot contain '@', '#' or space" && return 1
    [[ "$1" =~ $allnums ]] && echo "-username cannot contain only numbers" && return 1
    return 0
}

# Function to prompt the user for a new password and confirm that password.
# Retries until the passwords match.
new_password()
{
    local p1
    local p2
    local var="$1"
    local label="$2"
    local func="$3"

    while [[ -z "$p1" || "$p1" != "$p2" ]]; do
        promptfor p1 "Enter $label" true "" "$func"
        promptfor p2 "Confirm $label" true ""  # No validation needed

        if [[ "$p1" != "$p2" ]]; then
            echo "Passwords do not match"
        fi
    done

    eval "$var='$p1'"
}

# Determine our supported OSes and distributions
set_os_distribution()
{
    # Determine distribution
    if [[ -e "/etc/redhat-release" ]]; then
        DISTRO="RHEL"
    elif [[ -e "/etc/debian_version" ]]; then
        DISTRO="UBUNTU"
    else
        die "cannot determine distribution for this OS ($(uname -s)); abort"
    fi
}

# Ensure we can connect to defined P4PORT
# Detect if we need to trust SSL Helix Versioning Engine
# Detect if we need to set a charset
# Sets P4 for use elsewhere
check_p4port()
{
    [[ -z "$P4PORT" ]] && echo "No P4PORT specified" && return 1
    local info

    if ! $FORCE ; then
        echo "Checking P4PORT [$P4PORT]..."
    fi

    # Initialize p4 command with full path to binary
    P4="$P4_BINDIR/p4"
    if [[ ! -x "$P4" ]]; then
        echo "-cannot find [$P4]; checking if 'p4' in path..."
        if which p4 > /dev/null; then
            P4="$(which p4)"
            echo "-found [$P4]"
        else
            die "could not find a 'p4' binary; abort"
        fi
    fi

    # Add the p4port to the command string
    P4="$P4 -p $P4PORT"

    # Establish SSL trust first if required.
    if [[ "${P4PORT:0:3}" == "ssl" ]]; then
        echo "-establishing trust to [$P4PORT]..."
        $P4 trust -f -y || return $?
    fi

    # Set character-set explicitly if talking to a unicode server
    echo "Checking to see if Helix Versioning Engine is running in Unicode mode..."
    if $P4 -ztag info | grep -q '^\.\.\. unicode enabled'; then
        local p4charset="${P4CHARSET:-utf8}"
        echo "-Unicode Helix Versioning Engine detected; setting charset to [$p4charset]..."
        P4="$P4 -C $p4charset"
    fi

    # Explicitly set the client if we detect an illegal client name
    if $P4 -ztag info | grep -q '^\.\.\. clientName \*illegal\*'; then
        local p4client="$DEFAULT_SWARM_HOST"
        p4client="${p4client%%.*}-client"
        echo "-setting client name to [$p4client]..."
        P4="$P4 -c $p4client"
    fi

    echo "-P4 command line to use: [$P4]"

    $FORCE && ! $CREATE && [[ -n "$P4PORT" ]] && return 0

    echo "Attempting connection to [$P4PORT]..."
    info="$($P4 info 2> /dev/null | egrep "^Server(ID| (address|version|license))" | sort)"
    if [[ $? -ne 0 || -z "$info" ]]; then
        echo "-unable to connect"
        return 1
    else
        echo "-connection successful:"
        echo "$info" | sed -e "s,^,  ,g"
    fi

    return 0
}

# Simple check to see if supplied user exists or not
# Append 'quiet' to command to suppress output: check_p4_user <user> quiet
check_p4_user()
{
    $FORCE && return 0
    local quiet=false
    [[ -z "$1" ]] && echo "No user specified" && return 1
    [[ "$2" == "quiet" ]] && quiet=true
    local user="$1"
    local p4user

    [[ -n "$SUPER_USER" ]] &&
        p4user="$SUPER_USER" ||
        p4user="$user"

    $quiet || echo "-checking if user [$user] exists in [$P4PORT]..."
    if $P4 -u "$p4user" users 2>&1 | awk '{print $1}' | grep -qx "$user"; then
        $quiet || echo "-user exists"
        USER_EXIST=1
        return 0
    else
        # Just in case p4 users requires login, try to get a ticket
        if echo "$P4PORT" | $P4 -u "$user" login -p 2>&1 | grep -q 'User'; then
            $quiet || echo "-user does not exist"
            return 1
        else
            $quiet || echo "-user exists"
            USER_EXIST=1
            return 0
        fi
    fi
}

# Obtains a ticket by using 'login -p' with the password
# Outputs ticket
get_ticket_from_login()
{
    local user="$1"
    local passwd="$2"
    local ticket

    ticket="$(echo "$passwd" | $P4 -u "$user" login -p | egrep "^[0-9A-Z]{32}$" | head -n1 )"
    if [[ $? -ne 0 ]] || ! validate_ticket "${ticket}"; then
        return 1
    fi

    echo "$ticket"
    return 0
}

# Logs in using the ticket to verify it works
test_p4_user_ticket()
{
    local user="$1"
    local ticket="$2"

    echo "Checking user [$user]'s ticket against [$P4PORT]..."
    # Test the ticket by looking at the user's form
    if $P4 -ztag -u "$user" -P "$ticket" user -o | grep -qx "\.\.\. User $user"; then
        echo "-login ticket is good"
        return 0
    else
        echo "-unable to login with ticket"
        return 1
    fi
}

# Manages process to get a ticket
# Use password if it looks like a ticket
# Retrieve ticket
# Test the ticket
get_p4_login_ticket()
{
    local user="$1"
    local passwd="$2"
    local ticket_var="$3"
    local ticket

    echo "Obtaining Helix login ticket for [$user] in [$P4PORT]..."
    [[ -z "$passwd" ]] && echo "-no password specified" && return 1

    # Check if password specified is a login ticket
    if [[ "$passwd" =~ ^[0-9A-Z]{32}$ ]]; then
        echo "-password specified looks like a login ticket"
        ticket="$passwd"
    else
        ticket="$(get_ticket_from_login "$user" "$passwd")"
        if [[ $? -ne 0 ]]; then
            echo "-unable to obtain ticket"
            return 1
        else
            echo "-login ticket obtained"
        fi
    fi

    # Now test the ticket
    if ! test_p4_user_ticket "$user" "$ticket"; then
        # Pretty serious
        die "obtained ticket, but ticket check failed; abort"
    fi

    eval "$ticket_var=$ticket"
    return 0
}

check_p4_user_login_timeout()
{
    $FORCE && echo "-force flag set; skipping login timeout check" && return 0

    local user="$1"
    local ticket="$2"
    local ticket_expiry

    echo "Checking login timeout for [$user]..."
    ticket_expiry="$($P4 -u "$user" -P "$ticket" login -s | sed -re "s,.*expires in ([0-9]+) hours.*,\1,")"
    if [[ $? -ne 0 || -z "$ticket_expiry" ]]; then
        die "trouble obtaining ticket expiry for [$user]; abort"
    else
        echo "-ticket will expire in [$ticket_expiry] hours"
    fi

    # Check if expiry is less than 365 days
    if [[ "$ticket_expiry" -lt $((24 * 365)) ]]; then
        warn "The ticket for user [$user] will expire in [$ticket_expiry] hours, less than 365 days.
We recommend you add this user to a group with a longer timeout."
    fi

    return 0
}

# Simple check to make sure user has at least a certain Helix access level
# Only admin and super are supported now
check_p4_min_access()
{
    local user="$1"
    local ticket="$2"
    local min_access="$3"
    local access
    local p4user
    local user_to_check

    # Use super credentials if we have them
    if [[ -n "$SUPER_USER" ]]; then
        p4user="$SUPER_USER"
        if [[ -z "$SUPER_TICKET" ]]; then
            # Can happen if we specify super user on command line, but not -c
            # Get the ticket from the supplied password, since we never call
            # get_super_user in this particular case
            get_p4_login_ticket "$SUPER_USER" "$SUPER_PASSWD" "SUPER_TICKET"
        fi
        ticket="$SUPER_TICKET"
        user_to_check="-u $user"
    else
        p4user="$user"
    fi

    echo "Checking user [$user] has at least access level [$min_access]..."
    access="$($P4 -u "$p4user" -P "$ticket" protects -m $user_to_check)"
    if [[ $? -ne 0 || -z "$access" ]]; then
        echo "-problem obtaining access"
        return 1
    else
        echo "-user has maximum access level [$access]"
    fi

    # If max access is super, good
    # If max access is what min access is, good
    # If max access is admin, and min access is not super, good
    if [[ "$access" == "super" || "$access" = "$min_access" ||
        ( "$access" == "admin" && "$min_access" != "super" ) ]]; then
        echo "-user meets minimum access level [$min_access]"
        return 0
    fi

    # Only support checking against admin and super for now
    echo "-user access level [$access] is not at least [$min_access]"
    return 1
}

# Set the access level for a given user (requires superuser)
set_p4_access()
{
    local user="$1"
    local access="${2:-admin}"
    local first_super_line=""

    if [[ -z  "$user" ]]; then
        echo "-no user passed to set_p4_access(), bailing!"
        return 1
    fi

    # Do I have super access
    get_super_user
    local p4s="$P4 -u $SUPER_USER -P $SUPER_TICKET"

    # Insert the admin line at the end, where it's guaranteed to be 'right'
    echo "-appending '$access user $user * //...' to the protections table"
    $p4s protect -o | sed -e "\$a\\\t${access} user $user * //..." | $p4s protect -i
    if [[ $? -ne 0 ]]; then
        echo "-problem inserting protections line"
        return 1
    fi
    echo "-user access level [$access] set for user [$user]"
    return 0
}

# Output the value of a p4 counter value
get_p4_counter_value()
{
    local counter="$1"

    value="$($P4 -ztag -u "$SWARM_USER" -P "$SWARM_TICKET" counter "$counter" |
        sed -n "/^\.\.\. value /s///p")"
    if [[ $? -ne 0 || -z "$value" ]]; then
        return 1
    fi

    echo "$value"
    return 0
}

# Output the value of a p4 configuration value; requires super
get_p4_config_value()
{
    local super="$1"
    local ticket="$2"
    local variable="$3"
    local value

    value="$($P4 -ztag -u "$super" -P "$ticket" configure show "$variable" |
        sed -n "/^\.\.\. Value /s///p")"
    if [[ $? -ne 0 || -z "$value" ]]; then
        return 1
    fi

    echo "$value"
    return 0
}

# Set any configuration necessary by the super user
set_super_config()
{
    local super="$1"
    local ticket="$2"
    local keys_hide

    echo "Checking configuration value of dm.keys.hide..."
    keys_hide="$(get_p4_config_value "$super" "$ticket" "dm.keys.hide")"
    if [[ $? -ne 0 || -z "$keys_hide" ]]; then
        warn "Unable to obtain configuration value for dm.keys.hide."
        return 1
    else
        echo "-value is [$keys_hide]"
    fi

    if [[ $keys_hide -lt 2 ]]; then
        echo "-setting dm.keys.hide=2..."
        $P4 -u "$super" -P "$ticket" configure set dm.keys.hide=2
        if [[ $? -ne 0 ]]; then
            warn "Unable to set dm.keys.hide=2.
Without this, users will be able to set keys, potentially corrupting Swarm."
            return 1
        fi

        echo "-value set"
    else
        echo "-value is good"
    fi

    return 0
}

# Prompt the user for a superuser.
get_super_user()
{
  while ! check_super_creds; do
      cat << __SWARM_USER__

To make modifications to the Helix service, we need to use a Helix account
with 'super' rights. Please provide a username and password for this
account.

__SWARM_USER__
      $INTERACTIVE || die "invalid or unsupplied super user credentials."

      # Check for p4dctl and grab the P4USER from that if found (otherwise default to perforce)
      P4DCTL="$(which p4dctl 2>/dev/null)"
      if [ ! -z "$P4DCTL" ]; then
          P4DCTL_DEFAULT="$($P4DCTL list -t p4d 2>&1 | sed -n '2p' | awk '{ print $3; }')"
      fi
      if [ ! -z "$P4DCTL_DEFAULT" ]; then
          SUPER_USER="$($P4DCTL env $P4DCTL_DEFAULT -t p4d P4USER | sed 's/.*=//g')"
      fi
      promptfor SUPER_USER "Helix username for the super user" false "$SUPER_USER" validate_nonempty
      promptfor SUPER_PASSWD "Helix password or login ticket for the super user" true "" validate_nonempty
  done

  # Determine any user creation constraints
  check_p4_user_creation "$SUPER_USER" "$SUPER_TICKET"
  #
  # Set any configuration stuff
  set_super_config "$SUPER_USER" "$SUPER_TICKET"

  return 0
}


# Check super user credentials passed in from user
# Only necessary when creating Swarm user
check_super_creds()
{
    echo "Checking super user credentials..."
    check_p4_user "$SUPER_USER" || return $?
    get_p4_login_ticket "$SUPER_USER" "$SUPER_PASSWD" "SUPER_TICKET" || return $?
    check_p4_min_access "$SUPER_USER" "$SUPER_TICKET" "super" || return $?
}

# Check user creation constraints
check_p4_user_creation()
{
    local super="$1"
    local super_ticket="$2"
    local trigger_types=""
    local auth_check_sso=false
    local auth_check=false
    local auth_set=false

    [[ -z "$super" ]] && echo "-no super user specified" && return 1
    [[ -z "$super_ticket" ]] && echo "-no super user ticket specified" && return 1

    local p4cmd="$P4 -u $super -P $super_ticket"

    echo "Checking Helix user account creation constraints..."

    echo "-checking for auth triggers..."
    trigger_types="$($p4cmd triggers -o | grep "^[^#]" | awk '{print $2}')"
    if [[ $? -ne 0 ]]; then
        die "trouble obtaining trigger types; abort"
    fi
    echo "$trigger_types" | grep -q "auth-check-sso" && auth_check_sso=true
    echo "$trigger_types" | grep -q "auth-check" && auth_check=true
    echo "$trigger_types" | grep -q "auth-set" && auth_set=true

    echo "-auth-check-sso trigger? [$($auth_check_sso && echo yes || echo no)]"
    echo "-auth-check trigger?     [$($auth_check && echo yes || echo no)]"
    echo "-auth-set trigger?       [$($auth_set && echo yes || echo no)]"

    # Catch if auth-check-sso is in place
    if $auth_check_sso && ! $auth_check; then
        warn "\
Swarm is incompatible with a Helix Versioning Engine that uses an auth-check-sso trigger.
You can add an auth-check trigger which can act as a fall-back."
    fi

    CAN_SET_P4_PASSWD=true
    if $auth_check; then
        warn "\
Your Helix Versioning Engine at [$P4PORT] has an 'auth-check' trigger.
Please ensure the Swarm user [$SWARM_USER] exists in your
external authentication system."

        ! $auth_set && CAN_SET_P4_PASSWD=false
    fi

    return 0
}

# Check for the Swarm user
# Complain if it already exists if we're going to create it
check_swarm_user()
{
    $FORCE && [[ -n "$SWARM_USER" ]] && echo "-force flag set; skipping" && return 0
    [[ -z "$SWARM_USER" ]] && echo "No Swarm user specified" && return 1

    if check_p4_user "$SWARM_USER"; then
        # if user exists, fail if we're creating, else succeed
        if $CREATE; then
            echo "-Swarm user cannot already exist if creating credentials; drop -c flag or try -f flag?"
            return 1
        else
            return 0
        fi
    else
        # if user does not exist, succeed if we're creating, else fail
        $CREATE && return 0 || return 1
    fi
}

# The Helix stuff to create a Swarm user
# Creates the user itself
# Sets the password (and then gets the ticket)
create_p4_swarm_user()
{
    [[ -z "$SUPER_USER" ]] && echo "-no super user defined" && return 1
    [[ -z "$SUPER_TICKET" ]] && echo "-no super user ticket defined" && return 1
    [[ -z "$SWARM_PASSWD" ]] && echo "-no Swarm password set" && return 1

    local p4cmd="$P4 -u $SUPER_USER -P $SUPER_TICKET"
    local p4cmd_return

    echo "-creating Helix user [$SWARM_USER]..."
	$p4cmd user -o "$SWARM_USER" | sed -e "s/^FullName:.*/FullName: Swarm Admin/" | $p4cmd user -i -f
    if [[ $? -ne 0 ]] || ! check_p4_user "$SWARM_USER"; then
        die "trouble creating user; abort"
        return 1
    else
        echo "-user created"
    fi

    if $CAN_SET_P4_PASSWD; then
        echo "-setting password for Helix user [$SWARM_USER]..."
        echo -e "$SWARM_PASSWD\n$SWARM_PASSWD" | $p4cmd password "$SWARM_USER"
        p4cmd_return=$?

        if [[ $p4cmd_return -ne 0 && $USER_EXIST -ne 0 ]]; then
            echo -e "\n-trouble settting password and user already exists in Helix, not cleaning it up..."
            return 1
        elif [[ $p4cmd_return -ne 0 ]]; then
            echo -e "\n-trouble setting password (any error above?); cleaning up to try again..."
            $p4cmd user -d -f "$SWARM_USER"
            return 1
        else
            echo "-password set"
        fi
    else
        echo "-skipping setting password due to auth-check trigger"
    fi

    if ! get_p4_login_ticket "$SWARM_USER" "$SWARM_PASSWD" "SWARM_TICKET"; then
        # Maybe it's an access problem?
        if ! check_p4_min_access "$SWARM_USER" "$SWARM_TICKET" "list"; then
            echo -e "\n-user [$SWARM_USER] has no Helix access; update protections table to grant access and try again?"
        else
            echo -e "\n-trouble obtaining user ticket (any error above?)"
        fi
        echo "-cleaning up..."
        $p4cmd user -d -f "$SWARM_USER"
        die "could not obtain user ticket; abort"
    fi

    if $INTERACTIVE; then
        if prompt_yn "Create a long-lived ticket group for the Swarm Helix user?" "y"; then
            CREATE_GROUP=true
        else
            CREATE_GROUP=false
        fi
    fi

    if $CREATE_GROUP; then
        echo "-creating long-lived ticket group [swarm-group]..."
        $p4cmd group -o swarm-group | sed 's/^Timeout:.*$/Timeout: unlimited/' | awk "/^Users:/{print;print \"\t$SWARM_USER\";next}1" | $p4cmd group -i

        # We have to perform another user login for the group timeout to take effect.
        # Note, we expect the ticket value itself be the same as it was during
        # earlier get_p4_login_ticket(), as captured in $SWARM_TICKET
        get_ticket_from_login "$SWARM_USER" "$SWARM_PASSWD" > /dev/null || warn "Could not log in user [$SWARM_USER] after creating long-lived ticket group."
    fi

    return 0
}

# Check Swarm credentials passed in from user
check_swarm_creds()
{
    if $FORCE && [[ -n $SWARM_USER && -n $SWARM_PASSWD ]]; then
            echo "-force flag set; skipping swarm user credential check" && return 0
    fi

    echo "Checking Swarm user credentials..."
    check_swarm_user || return $?

    if $CREATE; then
        get_super_user
        echo "Creating Swarm user [$SWARM_USER]..."
        create_p4_swarm_user || return $?
    else
        get_p4_login_ticket "$SWARM_USER" "$SWARM_PASSWD" "SWARM_TICKET" || return $?
    fi

    # If we're on security=3, or using LDAP, then use the ticket value instead of the password
    if [[ "$(get_p4_counter_value security)" == "3" ]]; then
        echo "-detected Helix Versioning Engine security=3; using ticket value instead of password"
        SWARM_PASSWD="$SWARM_TICKET"
    else
        local value
        value="$($P4 -ztag -u "$SWARM_USER" -P "$SWARM_PASSWD" info | grep "ldapAuth enabled" | sed 's/\.\.\. //g')"
        if [[ "$value" == "ldapAuth enabled" ]]; then
            echo "-detected Helix Versioning Engine has LDAP; using ticket value instead of password"
            SWARM_PASSWD="$SWARM_TICKET"
        fi
    fi

    # If the password specified is a ticket, check its timeout value
    if validate_ticket "$SWARM_PASSWD"; then
        check_p4_user_login_timeout "$SWARM_USER" "$SWARM_PASSWD" || return $?
    fi

    return 0
}

# Create cron configuration file for Swarm cron script
configure_cron()
{
    echo "Configuring Cron..."

    # Prepare the Swarm hostname string
    local swarm_cron_host=$SWARM_HOST

    if [ ! -z "$SWARM_PORT" ]; then
        swarm_cron_host="${swarm_cron_host}:${SWARM_PORT}"
    fi

    if [ ! -z "$BASE_URL" ]; then
        swarm_cron_host="${swarm_cron_host}/${BASE_URL}"
    fi

    # Set the Swarm hostname in the cron config file
    cat << __CRON_CONFIG__ > "$CRON_CONFIG.new"
# Helix Swarm cron configuration
#
# Format (one per line):
# [http[s]://]<swarm-host>[:<port>][/<base-url>]
#
$swarm_cron_host
__CRON_CONFIG__

    # Check if there's an existing config file
    if [[ -r "$CRON_CONFIG" ]]; then
        # Check if it's different from what we've just generated
        if ! diff -q "$CRON_CONFIG" "$CRON_CONFIG.new" > /dev/null; then
            echo "-renaming existing Swarm cron configuration..."
            mv -v "$CRON_CONFIG" "$CRON_CONFIG.$(date +%Y%m%d_%H%M%S)"
            mv -v "$CRON_CONFIG.new" "$CRON_CONFIG"
        else
            echo "-new Swarm cron configuration is same as existing one; removing..."
            rm -f "$CRON_CONFIG.new"
        fi
    else
        mv -v "$CRON_CONFIG.new" "$CRON_CONFIG"
    fi

    echo "-updated cron configuration file with supplied Swarm host"
}

# Configure Swarm configuration
# Replace P4PORT, Swarm user & password
# Set file permissions
configure_swarm()
{
    local php_config_export
    local new_config

    echo "Configuring Swarm installation..."
    # Use PHP to construct initial new values, and read in existing config.php
    # Anything we declare will "win", preserving other existing settings.
    local php_config_export="$(php -r "\
\$config = array(
    'environment'   => array(
        'hostname'  => '$SWARM_HOST',
    ),
    'p4' => array(
        'port'      => '$P4PORT',
        'user'      => '$SWARM_USER',
        'password'  => '$SWARM_PASSWD',
    ),
    'mail' => array(
        'transport' => array(
            'host'  => '$EMAIL_HOST',
        ),
    ),
);
file_exists('$SWARM_CONFIG') && is_array(\$old_config = include '$SWARM_CONFIG' ) && \$config = array_replace_recursive(\$old_config, \$config);
unset(\$config['environment']['base_url']);
if (strlen('$BASE_URL')) {
    \$config['environment']['base_url'] = '/' . '$BASE_URL';
}
var_export(\$config);")"
    local new_config="\
<?php
return $php_config_export;"

    if [[ $? -ne 0 || -z "$php_config_export" ]]; then
        die "trouble composing new configuration file contents"
    else
        echo "-composed new Swarm config file contents"
    fi

    if [ ! -z "$BASE_URL" ]; then
        warn "You will need to modify your Apache virtual host configuration
to serve Swarm at the provided base URL ($BASE_URL)."
    fi

    # Format it into our expected style
    echo "$new_config" |
        tr '\n' '\r' |
        sed -e 's/=>\s*array/=> array/g;s/array (/array(/g;s/  /    /g' |
        tr '\r' '\n' > "$SWARM_CONFIG.new"

    # Check if there's an existing config file
    if [[ -r "$SWARM_CONFIG" ]]; then
        # Check if it's different from what we've just generated
        if ! diff -q "$SWARM_CONFIG" "$SWARM_CONFIG.new" > /dev/null; then
            echo "-renaming existing Swarm config file..."
            mv -v "$SWARM_CONFIG" "$SWARM_CONFIG.$(date +%Y%m%d_%H%M%S)"
            mv -v "$SWARM_CONFIG.new" "$SWARM_CONFIG"
        else
            echo "-new Swarm config file is same as existing one; removing..."
            rm -f "$SWARM_CONFIG.new"
        fi
    else
        mv -v "$SWARM_CONFIG.new" "$SWARM_CONFIG"
        echo "-wrote new Swarm config file to reflect new configuration"
    fi

    # Ensure permissions are tight on the data directory
    local apache_user_var="APACHE_USER_${DISTRO}"
    local apache_user="${!apache_user_var}"
    [[ -n "$apache_user" ]] || die "cannot obtain APACHE_USER"

    local apache_group_var="APACHE_GROUP_${DISTRO}"
    local apache_group="${!apache_group_var}"
    [[ -n "$apache_group" ]] || die "cannot obtain APACHE_GROUP"
    echo "-identified Apache user:group: [$apache_user:$apache_group]"

    echo "-setting permissions on the Swarm data directory..."
    chown -R $apache_user:$apache_group "$SWARM_DIR/data"
    # Restrict public access to data directory due to potentially sensitive info in config.php
    chmod -R o= "$SWARM_DIR/data"
    echo "-ensured file permissions are set properly"
}

# Check Apache vhost file, and replace hostname with SWARM_HOST
# If SWARM_PORT is not 80, use it to replace the virtual host port setting
# Ensure right modules are enabled
# Tweak vhost file on Apache 2.4
configure_apache()
{
    echo "Configuring Apache..."
    local vhost_config_var="VHOST_CONFIG_${DISTRO}"
    local vhost_config="${!vhost_config_var}"

    # Copy over the template
    cp $VHOST_CONFIG_SRC $vhost_config
    [[ -s "$vhost_config" ]] || die "invalid vhost config file [$vhost_config]"
    echo "-identified Swarm virtual host config file: [$vhost_config]"

    local apache_log_dir_var="APACHE_LOG_DIR_${DISTRO}"
    local apache_log_dir="${!apache_log_dir_var}"
    [[ -d "$apache_log_dir" ]] || die "invalid Apache log directory [$apache_log_dir]"
    echo "-identified Apache log directory: [$apache_log_dir]"

    # Replace the holder for the Apache log directory in the vhost config file
    if ! sed -e "s#APACHE_LOG_DIR#${apache_log_dir}#g" "$vhost_config" > "$vhost_config.$$"; then
        rm -f "$vhost_config.$$"
        die "trouble setting Apache log dir in [$vhost_config]"
    fi
    mv -f "$vhost_config.$$" "$vhost_config"
    echo "-updated the vhost file to set Apache log directory"

    # Set the Swarm hostname in the vhost config file
    if ! sed -e "s/ServerName .*/ServerName ${SWARM_HOST}/g" "$vhost_config" > "$vhost_config.$$"; then
        rm -f "$vhost_config.$$"
        die "trouble setting ServerName in [$vhost_config]"
    fi
    mv -f "$vhost_config.$$" "$vhost_config"
    echo "-updated the vhost file to reflect Swarm host"

    # If SWARM_PORT is not port 80, update the port in the vhost config file
    if [[ $SWARM_PORT != 80 ]]; then
        local re='^[0-9]+$'
        if ! [[ $SWARM_PORT =~ $re && $SWARM_PORT -gt 0 && $SWARM_PORT -lt 65535 ]]; then
           die "-VirtualHost port must be a valid integer between 1 and 65534"
        fi
        if ! sed -e "s/VirtualHost \*:80/VirtualHost *:${SWARM_PORT}/g" "$vhost_config" > "$vhost_config.$$"; then
            rm -f "$vhost_config.$$"
            die "trouble setting VirtualHost port in [$vhost_config]"
        fi
        mv -f "$vhost_config.$$" "$vhost_config"
        echo "-updated the vhost file to reflect Swarm port"

        local apache_port_conf_var="APACHE_PORT_CONF_${DISTRO}"
        local apache_port_conf="${!apache_port_conf_var}"
        warn "You must now configure Apache to listen on the port you specified.
Update your Apache configuration file [$apache_port_conf]
with the line below, and then restart Apache:
    Listen ${SWARM_PORT}"

        if [[ $SWARM_PORT == 443 ]]; then
            warn "Port 443 is reserved for HTTPS connections.
You must ensure that your Swarm virtual host and Apache
installation are properly configured for HTTPS."
        fi
    fi

    # Obtain the version of Apache we're using
    local apache_version="$(apachectl -v | grep '^Server version' | sed -e 's,.*Apache/\([0-9]\.[0-9]\).*,\1,')"
    [[ -n "$apache_version" ]] || die "Cannot determine Apache version"

    # Check what version of Apache we're using
    case "$apache_version" in
    '2.4')
        # Modify the directives (for 2.2) to work with 2.4
        sed -e "/Order allow,deny/d;s,Allow from all,Require all granted," "$vhost_config" > "$vhost_config.$$"
        mv -f "$vhost_config.$$" "$vhost_config"
        echo "-updated the vhost file to handle Apache 2.4 directives"
        ;;
    '2.2')
        : # Nothing to do
        ;;
    *)
        warn "Unknown version of Apache ($apache_version)."
        ;;
    esac

    # Ensure our modules are enabled per distro
    case "$DISTRO" in
        'RHEL')
            echo "-checking Apache modules..."
            for module in 'rewrite_module' "${RHEL_APACHE_PHP_MODULE}" ; do
                if ! apachectl -t -D DUMP_MODULES | grep -q "$module" ; then
                    die "Apache module [$module] not enabled."
                fi
            done
            echo "-proper Apache modules are enabled"

            echo "-checking Apache is configured to start on boot..."
            chkconfig httpd on
            apachectl restart
            echo "-Apache is now configured to start on boot, and is running"
            ;;
        'UBUNTU')
            echo "-checking Apache modules..."
            a2enmod rewrite "${UBUNTU_APACHE_PHP_MODULE}"
            echo "-proper Apache modules are enabled"

            echo "-enabling Swarm Apache site..."
            a2ensite perforce-swarm-site.conf
            echo "-Swarm Apache site enabled"

            echo "-restarting Apache..."
            apachectl restart
            echo "-Apache restarted"
            ;;
        *)
            die "unknown distribution [$DISTRO]"
            ;;
    esac
}

# Display what we've determined based on the supplied arguments
preamble()
{
    local notpassed="not specified"
    local passwd_there="present, but hidden"
    local suggest="will suggest"
    local baseUrlDefault="default (empty)"
    local p4portnote=
    local supernote=
    $INTERACTIVE &&
        notpassed="$notpassed, will prompt" ||
        suggest="will use"
    ! $CREATE &&
        notpassed="not specified" &&
        supernote=" * not needed"
    [[ ! $P4PORT_PASSED && -n "$P4PORT" ]] &&
        p4portnote=" * obtained from environment"

    cat << __PREAMBLE__
------------------------------------------------------------
$ME: $(date): commencing configuration of Swarm

Summary of arguments passed:
Interactive?       [$($INTERACTIVE && echo yes || echo no)]
Force?             [$($FORCE && echo yes || echo no)]
P4PORT             [${P4PORT:-($notpassed)}]$p4portnote
Swarm user         [${SWARM_USER:-($notpassed, $suggest "$DEFAULT_SWARM_USER")}]
Swarm password     [$([[ -n "$SWARM_PASSWD" ]] && echo "($passwd_there)" || echo "($notpassed)")]
Email host         [${EMAIL_HOST:-($notpassed)}]
Swarm host         [${SWARM_HOST:-($notpassed, $suggest "$DEFAULT_SWARM_HOST")}]
Swarm port         [${SWARM_PORT}]
Swarm base URL     [${BASE_URL:-($baseUrlDefault)}]
Create Swarm user? [$($CREATE && echo yes || echo no)]
Super user         [${SUPER_USER:-($notpassed)}]$supernote
Super password     [$([[ -n "$SUPER_PASSWD" ]] && echo "($passwd_there)" || echo "($notpassed)")]$supernote

__PREAMBLE__
}

#-------------------------------------------------------------------------------
# Start of main functionality
#-------------------------------------------------------------------------------

# Force server to respond in English. This fixes problems where the script is looking
# for certain response text from the server.
export P4LANGUAGE=en

# Define arguments
ARGS="$(getopt -n "$ME" \
    -o "fnp:u:w:e:H:P:B:cgU:W:hq" \
    -l "force,non-interactive,p4port:,swarm-user:,swarm-passwd:,email-host:,swarm-host:,swarm-port:,base-url:,create,create-group,super-user:,super-passwd:,help,quiet" \
    -- "$@")"
if [ $? -ne 0 ]; then
    usage_error
fi

# Reinject args from getopt, so we know they're valid and in the right order
eval set -- "$ARGS"

# Default args
INTERACTIVE=true
NON_INTERACTIVE=false
FORCE=false
CREATE=false
CREATE_GROUP=false
P4PORT_PASSED=false
BASE_URL=
SWARM_PORT=80
QUIET=false

# Evaluate arguments
while true; do
    case "$1" in
        -f|--force)             FORCE=true;             shift ;;
        -n|--non-interactive)   NON_INTERACTIVE=true;   shift ;;
        -p|--p4port)            P4PORT="$2";            shift 2 ; P4PORT_PASSED=true;;
        -u|--swarm-user)        SWARM_USER="$2";        shift 2 ;;
        -w|--swarm-passwd)      SWARM_PASSWD="$2";      shift 2 ;;
        -e|--email-host)        EMAIL_HOST="$2";        shift 2 ;;
        -H|--swarm-host)        SWARM_HOST="$2";        shift 2 ;;
        -P|--swarm-port)        SWARM_PORT="$2";        shift 2 ;;
        -B|--base-url)          BASE_URL="$2";          shift 2 ;;
        -c|--create)            CREATE=true;            shift ;;
        -g|--create-group)      CREATE_GROUP=true;      shift ;;
        -U|--super-user)        SUPER_USER="$2";        shift 2 ;;
        -W|--super-passwd)      SUPER_PASSWD="$2";      shift 2 ;;
        -h|--help)              usage_help;             shift ;;
        -q|--quiet)             QUIET=true;             shift ;;
        --) shift ; break ;;
        *)  usage_error "command-line syntax error" ;;
    esac
done

if [ "$NON_INTERACTIVE" == true ]; then
    INTERACTIVE=false
fi

# Sanity check
[[ $INTERACTIVE == 'false' && -z "$P4PORT" ]] && usage_error "-p p4port required"
[[ $INTERACTIVE == 'false' && -z "$SWARM_USER" ]] && usage_error "-u swarm-user required"
[[ $INTERACTIVE == 'false' && -z "$SWARM_PASSWD" ]] && usage_error "-w swarm-passwd required"
[[ $INTERACTIVE == 'false' && -z "$EMAIL_HOST" ]] && usage_error "-e email-host required"
[[ $INTERACTIVE == 'false' && $CREATE == 'true' && -z "$SUPER_USER" ]] && usage_error "Super user required"
[[ $INTERACTIVE == 'false' && $CREATE == 'true' && -z "$SUPER_PASSWD" ]] && usage_error "Super password required"

# Fail if not running as root.
[[ "$(whoami)" == "root" ]] || die "Please run this script as root"

# This script won't run correctly without a home directory
if [ -z "$HOME" ]; then export HOME="/root"; fi

# This script can't run without an install dir/config dir at the expected locations
if [ ! -d "${SWARM_DIR}" ]; then
    die "Directory '${SWARM_DIR}' does not exist. This may mean that Swarm is not installed at the proper location."
fi
if [ ! -d "${P4_INSTALL_ROOT}" ]; then
    die "Directory '${P4_INSTALL_ROOT}' does not exist. Nothing to do."
fi
if [ ! -d "${P4_CONFIG_DIR}" ]; then
    die "Directory '${P4_CONFIG_DIR}' does not exist. Nothing to do."
fi

# trim slashes from BASE_URL
if [ ! -z "$BASE_URL" ]; then
    BASE_URL="$(echo -n "$BASE_URL" | sed 's,^[/\\]*,,' | sed 's,[/\\]*$,,')"
fi

# Check log we're about to start writing to
touch "$LOG" || die "Trouble opening log file [$LOG]; abort"

# Save stdout/stderr and then redirect them to the display & log
exec 3>&1 4>&2
exec &> >(tee -a "$LOG")

# Display preamble
preamble

# Determine our OS and distro
set_os_distribution

# Obtain the P4PORT
while ! check_p4port; do
    $INTERACTIVE || die "invalid P4PORT"
    cat << __P4PORT__

Swarm requires a connection to a Helix Versioning Engine.
Please supply the P4PORT to connect to.

__P4PORT__

    # Check for p4dctl and grab the P4PORT from that if found (otherwise default to 1666 or ssl:1666)
    P4DCTL="$(which p4dctl 2>/dev/null)"
    if [ ! -z "$P4DCTL" ]; then
        P4DCTL_DEFAULT="$($P4DCTL list -t p4d 2>&1 | sed -n '2p' | awk '{ print $3; }')"
    fi
    if [ ! -z "$P4DCTL_DEFAULT" ]; then
        P4PORT="$($P4DCTL env $P4DCTL_DEFAULT -t p4d P4PORT | sed 's/P4PORT=//')"
    fi
    promptfor P4PORT "Helix Versioning Engine address (P4PORT)" false "$P4PORT" validate_nonempty
done

# Obtain the Swarm user credentials
while ! check_swarm_creds; do
    $INTERACTIVE || die "invalid Swarm credentials"
    cat << __SUPER__

Swarm requires a Helix user account with 'admin' rights.
Please provide a username and password for this account.
If this account does not have 'admin' rights, it will
be set for this user.

__SUPER__
    promptfor SWARM_USER "Helix username for the Swarm user" false "${SWARM_USER:-$DEFAULT_SWARM_USER}" validate_username
    if ! check_p4_user $SWARM_USER quiet ; then
        # user doesn't exist - check to see if CREATE passed.  If not, prompt for creation
        if ! $CREATE; then
            if prompt_yn "User $SWARM_USER doesn't exist.  Create it?" "y"; then
                CREATE=true
            else
                SWARM_USER=""
                continue # get another name
            fi
        fi
        # Get password for the new user
        new_password SWARM_PASSWD "Helix password (not a login ticket) for the Swarm user" validate_noticket
    else
        promptfor SWARM_PASSWD "Helix password or login ticket for the Swarm user" true "" validate_nonempty
    fi
done

# Swarm credentials are good, set minimum access if required.
# Skip if we set -f
if ! $FORCE ; then
    get_p4_login_ticket "$SWARM_USER" "$SWARM_PASSWD" "SWARM_TICKET"
    if ! check_p4_min_access "$SWARM_USER" "$SWARM_TICKET" "admin"; then
        echo "-The Swarm user [$SWARM_USER] needs 'admin' access to Helix."
        echo " The script will now add the following line to your protections table:"
        echo "    admin user $SWARM_USER * //..."
        set_p4_access "$SWARM_USER"
    fi
fi

# Obtain the Swarm host
while [[ -z "$SWARM_HOST" ]]; do
    if ! $INTERACTIVE; then
        echo "Using default hostname [$DEFAULT_SWARM_HOST] for Swarm hostname..."
        SWARM_HOST="$DEFAULT_SWARM_HOST"
        [[ -z "$SWARM_HOST" ]] &&
            die "unable to set SWARM_HOST"
        echo "-done"
    else
        cat << __HOSTNAME__

Swarm needs a distinct hostname that users can enter into their browsers to
access Swarm. Ideally, this is a fully-qualified domain name, e.g.
'swarm.company.com', but it can be just a hostname, e.g. 'swarm'.

Whatever hostname you provide should be Swarm-specific and not shared with
any other web service on this host.

Note that the hostname you specify typically requires configuration in your
network's DNS service. If you are merely testing Swarm, you can add a
hostname->IP mapping entry to your computer's hosts configuration.

__HOSTNAME__
    promptfor SWARM_HOST "Hostname for this Swarm server" false "${SWARM_HOST:-$DEFAULT_SWARM_HOST}" validate_nonempty
    fi
done

# Obtain the email relay
while [[ -z "$EMAIL_HOST" ]]; do
    $INTERACTIVE || die "invalid email host"
    cat << __EMAIL_HOST__

Swarm requires an mail relay host to send email notifications.

__EMAIL_HOST__
    promptfor EMAIL_HOST "Mail relay host (e.g.: mx.yourdomain.com)" false "" validate_nonempty
done

# Now apply configuration
configure_cron
configure_swarm
configure_apache

# Restore stdout & stderr
exec >&3 >&3- 2>&4 >&4-

# All done
echo "$ME: $(date): completed configuration of Helix Swarm"

if ! $QUIET; then
    echo ""
    echo "::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::"
    echo "::"

    if [ -z "$BASE_URL" -a "$SWARM_PORT" -eq 80 ]; then
        echo "::  Swarm is now configured and should be available at:"
        echo "::"
        echo "::      http://$SWARM_HOST/"
        echo "::"
        echo "::  Ensure that you have configured the Swarm hostname in your"
        echo "::  network's DNS, or have added an IP address-to-hostname"
        echo "::  mapping to your computer's hosts configuration so that you"
        echo "::  can access Swarm."
        echo "::"
    else
        echo "::  Swarm configuration is almost complete. See the WARNINGS section"
        echo "::  below for information about the next steps for installing Swarm"
        echo "::  with your custom configuration."
        echo "::"
    fi

    cat << __SUMMARY__
::  You may login as the Swarm user [$SWARM_USER] using the password
::  you specified.
::
::  Please ensure you install the following package on the server
::  hosting your Helix Versioning Engine.
::
::      helix-swarm-triggers
::
::  (If your Helix Versioning Engine is hosted on an OS and
::  platform that is not compatible with the above package, you can
::  also install the trigger script manually.)
::
::  You will need to configure the triggers, as covered in the Swarm
::  documentation:
::
::  https://www.perforce.com/perforce/doc.current/manuals/swarm/setup.perforce.html
::
::  Documentation for optional post-install configuration, such as
::  configuring Swarm to use HTTPS, operate in a sub-folder, or on a
::  custom port, is available:
::
::  https://www.perforce.com/perforce/doc.current/manuals/swarm/setup.post.html
::
::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::
__SUMMARY__
fi

if [[ -n "$WARNINGS" ]]; then
    echo "============================================================"
    echo "WARNINGS DETECTED THAT MAY REQUIRE ACTION:"
    echo "$WARNINGS"
    echo "============================================================"
fi

exit 0
