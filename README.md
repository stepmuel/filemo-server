# Filemo Server

This repo contains the the proxy server used by the iOS Filemo app to access Arq backups.

To create a server, copy the files `index.php` and `store.php` to a folder served to the web. Alternatively, you can host the server directly on your machine using `php -S 0.0.0.0:8080 .`.

You also need to create `config.php`, according to the instructions in `config.template.php`.

If you have questions, suggestions, or want an invite to the iOS beta, please contact me at `stephan (at) heap.ch`, or on [via Twitter](https://twitter.com/stepmuel).

## Q&A

### What data does the Filemo Server expose?

All data requires the access token. The following data is available unencrypted:

* The Arq assigned uuid of all computers contained in the backup.
* The `computerinfo` files for all computers, containing computer name and user name.

All other data is encrypted and requires the backup password to decode:

* `encryptionv3.dat`, which is required in combination with the backup password to decrypt all other data.
* *Folder Configuration Files*, which contain additional information about the backup, like the name of the folder.
* All files contained in the backups, current and past, and when backups were made (commits).

The server can not provide more restrictive access to the encrypted files, because the encryption makes it impossible for it to know what it is serving.

### Why is a server needed?

**Speed**

The [Arq data format](https://www.arqbackup.com/arq_data_format.txt) combines small files into *packsets* for more efficient storage. A given file might be in any of potentially thousands of packsets, and hundreds of megabytes of indexes have to be scanned to find it. Doing this on in the app is not only slower, but also wastes bandwidth and storage space since all index files would have to be downloaded.

**Split Files**

Large files can be split into multiple parts, stored in separate encrypted files. The iOS FileProvider extension technology used by Filemo is designed to download a file with a single request, and working around this has many drawbacks. For example, files can't be downloaded in the background, which requires a lot more memory and the UI doesn't show a progress indicator.

**Limited Data Access**

The server can limit access to exactly the data listed in the previous section. Other data on the file system stays hidden. No data can be manipulated or deleted. This can be a benefit compared to for example giving the app access to the server via ssh.

**Extensibility**

In the future, the server can be extended to not only serve local files, but also provide access to backups stored in AWS, Backblaze, etc.

### How does caching work?

When creating a backup, all new files are put into the same packset until it is full. There is therefore a high chance that many files will be in the same packset when browsing a backup. The caching mechanism remembers the last time it found a file in a packset, and will check the most recent first.

Caching has to be enabled by providing a cache file path in `config.php`. Since packfiles are separated by its path, the same cache file can be used for multiple Filemo instances on the same machine.
