# Steam Profile Integration - v3.0.2

This app will obtain data from Steam for your users, and groups, and place it in your IPS Community database for use anywhere on your site. Your board will re-load your members Steam Online information, in configurable batches, every 2 minutes, so you always have up to date information without impacting load times of your community pages.

Out of the box, this app will display steam information in the following places

* Member Profile
* Next to Members' posts
* Member Hovercards (Steam Online Status only)
* Widget: X Random Online Steam Members displayed anywhere you can place a Widget
More detailed information about the members Steam profile is displayed on the Members Profile page.  Including an optional list of games the member owns.  Out of the box the list of games can be shown in either an image grid layout, or list view.

Valid Steam Input format for Custom Profile Field:

```
Steam Name: ex. ' Aiwa '
17 digit Steam ID: ex. 76561197964468370
Old school Steam ID: ex. STEAM_0:0:2101321
```
If there are any other places you'd like to see a users steam information displayed, let me know!

Scales seamlessly to work with larger boards without impacting site / server performance.

### Prerequisites

```
IPS 4.0.x & 4.1.x, use version 2.0.13
IPS 4.2 use  2.1.7
IPS 4.3 and 4.4,  2.1.13
IPS 4.7+ 3.0.x
```
```
Supported natively on 64 bit server configurations. 
For 32 bit server configurations, php-bcmath is required to decode this format.
```

### Installing

#### Step 1: Import the Application
* Log in to your ACP and visit the System tab -> Applications.
* Click on the 'manual upload' link, browse to the TAR file included with these instructions and click Open.


##### Step 2: Obtain an API Key

* Go to [Steam Community Dev](http://steamcommunity.com/dev) and acquire an [API key](http://steamcommunity.com/dev/apikey).


#### Step 3: Create a Custom Profile Field

Note: If you have an existing custom profile field with this data, edit the existing field and skip to #3.3 below.

* Go to ACP > Members > Profiles Fields.
* Select the + sign next to the group where you want the field to be.
* Field Type: Steam ID


#### Step 4: Configure App Settings

* Go to ACP > Community > Steam > Settings.  Here is where you'll need to put in your Steam API Key.

#### Step 5: Add the Widget to a page

* Navigate to your community homepage, forum index page.
* Open the 'Block Manager'
* Select 'Random Steam Profiles' and place anywhere on the page.
* Select 'Edit' on the block and configure its settings.


## Author

* **[Aiwa](https://aiwa.me)** - **[gitHub](https://github.com/kacx9c)**

## Acknowledgments
* IPS Underground