<?php
// src/Database.php
// ============================================================
//  Classe Database — Singleton PDO
//  Fournit une connexion unique à la base de données MariaDB.
//  Usage : $pdo = Database::get();
// ============================================================

class Database {

    /**
     * Instance PDO unique (patron Singleton).
     * On stocke la connexion ici une fois créée pour ne pas
     * ouvrir plusieurs connexions à la BDD inutilement.
     */
    private static ?PDO $instance = null;

    /**
     * Retourne (et crée si besoin) la connexion PDO partagée.
     *
     * Au premier appel :
     *  1. Charge la configuration depuis config/database.php
     *  2. Construit le DSN (chaîne de connexion MySQL)
     *  3. Crée l'objet PDO avec les options recommandées
     *
     * Aux appels suivants, retourne simplement l'instance déjà créée.
     */
    public static function get(): PDO {

        // Si la connexion n'a pas encore été créée, on l'initialise
        if (self::$instance === null) {

            // Charge le fichier de config (retourne un tableau associatif)
            // Exemple de contenu : ['host'=>'localhost','dbname'=>'proclasse','user'=>'...','password'=>'...','charset'=>'utf8mb4']
            $cfg = require __DIR__ . '/../config/database.php';

            // Construction du DSN (Data Source Name) pour MySQL/MariaDB
            // Format : mysql:host=HOTE;dbname=NOM_BDD;charset=ENCODAGE
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}";

            // Création de la connexion PDO
            self::$instance = new PDO($dsn, $cfg['user'], $cfg['password'], [
                // Lance une exception PHP en cas d'erreur SQL (au lieu de retourner false silencieusement)
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                // Par défaut, les résultats des requêtes sont retournés sous forme de tableau associatif
                // (ex: $row['nom'] au lieu de $row[0])
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Désactive l'émulation des requêtes préparées côté PHP
                // → PDO utilise les vraies prepared statements du serveur MariaDB
                // → meilleure sécurité contre les injections SQL
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        // Retourne la connexion existante (ou celle qu'on vient de créer)
        return self::$instance;
    }
}
