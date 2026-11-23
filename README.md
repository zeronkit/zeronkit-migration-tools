# ZeronKit Migration Tools

Free, open source tools that move a whole WoWonder social network to another platform. Each tool is one PHP file that runs on your own server: no licence key, no account, no internet connection, and your database details never leave your server.

| Tool | Moves | Target |
|---|---|---|
| [wowonder-to-sngine.php](wowonder-to-sngine.php) | Users, posts, comments, reactions, followers, friends, pages, groups, messages, hashtags, events, products, blogs, pokes, blocks | Sngine 4.x |
| [wowonder-to-socialengine.php](wowonder-to-socialengine.php) | Users, posts (as activity items), comments, likes, follows, messages (as conversations), blocks | SocialEngine 6.x |

Users keep their existing passwords: both targets accept WoWonder's bcrypt hashes, so nobody has to reset anything.

## How to use

1. **Back up** both databases and your files.
2. Install the target platform first (Sngine 4.x or SocialEngine 6.x), ideally fresh.
3. Upload the tool to your server's root folder and open it in your browser, for example `https://yourdomain.com/wowonder-to-sngine.php`.
4. Enter both database connections, press **Test Connection**, choose what to move and press **Start Migration**.
5. Copy the media files, check a few accounts, and **delete the tool from your server**.

Full step by step guides:
- [WoWonder to Sngine guide](https://zeronkit.com/wowonder-to-sngine/)
- [WoWonder to SocialEngine guide](https://zeronkit.com/wowonder-to-socialengine/)

Requires PHP with the mysqli extension and access to both databases.

## Licence

GNU General Public License v2 or later. Copyright ZeronKit. Provided as is, without warranty: always keep backups.

Website: [zeronkit.com](https://zeronkit.com) | All tools: [zeronkit.com/tools](https://zeronkit.com/tools/)
