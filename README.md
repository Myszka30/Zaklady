config.php
```php
<?php
$ip = "<ip serwera msql>";
$port = <port>;
$user = "<username>";
$pass = "<haslo>";
$baza = "<nazwa bazy>";

$conn = mysqli_connect($ip, $user, $pass, $baza, $port);

if (!$conn)
    die("Błąd połączenia z bazą danych");
?>
```
