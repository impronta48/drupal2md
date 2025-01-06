<?php
// Include la libreria Parsedown per la conversione da HTML a Markdown
require 'vendor/autoload.php';

use League\HTMLToMarkdown\HtmlConverter;

include('configure.php');

// Configura la directory di output per i file Markdown
$outputDir = __DIR__ . '/content/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Configura la directory di output le immagini
$assetsDir = __DIR__ . '/assets/';
if (!is_dir($assetsDir)) {
    mkdir($assetsDir, 0777, true);
}

// Connessione al database
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Errore di connessione al database: " . $e->getMessage());
}

// Funzione per estrarre i termini di tassonomia associati a un nodo
function getTaxonomyTerms($pdo, $nid)
{
    $query = "
        SELECT td.name
        FROM taxonomy_index ti
        JOIN taxonomy_term_data td ON ti.tid = td.tid
        WHERE ti.nid = :nid
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['nid' => $nid]);
    $terms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($terms) {
        return "- " . implode("\n- ", $terms); // Ritorna i termini come lista YAML
    }
}

// Funzione per ottenere il percorso dell'immagine di copertina
function getCopertina($pdo, $nid)
{
    $query = "
        SELECT fm.uri
        FROM file_managed fm
        JOIN field_data_field_copertina fi ON fm.fid = fi.field_copertina_fid
        WHERE fi.entity_id = :nid
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['nid' => $nid]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        // Converte il percorso da "public://example.jpg" a un percorso relativo
        return str_replace('public://', '/assets/', $result['uri']);
    }
    return null; // Nessuna immagine trovata
}

// Funzione per estrarre le immagini della gallery
function getGalleryImages($pdo, $nid)
{
    $query = "
        SELECT fm.uri
        FROM file_managed fm
        JOIN field_data_field_galleria fg ON fm.fid = fg.field_galleria_fid
        WHERE fg.entity_id = :nid
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['nid' => $nid]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $imagePaths = [];
    foreach ($images as $image) {
        $relativePath = str_replace('public://', '', $image['uri']);
        $imagePaths[] = $relativePath;
    }
    return $imagePaths; // Restituisce la lista delle immagini nella gallery
}

// Funzione per la conversione da HTML a Markdown (usando Parsedown)
function convertHtmlToMarkdown($html) {
    $converter = new HtmlConverter();
    if (!$html) {
        return null;
    }
    $markdown = $converter->convert($html);
    return $markdown;
}

// Query per ottenere i contenuti
$query = "
    SELECT n.nid, n.title, nr.body_value, nr.body_summary, n.created, n.type, u.name
    FROM node n
    JOIN field_data_body nr ON n.nid = nr.entity_id
    JOIN users u on n.uid = u.uid
    WHERE n.status = 1
";

$stmt = $pdo->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funzione per convertire timestamp in formato leggibile
function formatDate($timestamp)
{
    return date('Y-m-d H:i:s', $timestamp);
}

// Itera attraverso i contenuti e crea i file Markdown
foreach ($results as $row) {
    $nid = $row['nid'];
    $title = $row['title'];
    $body = $row['body_value'];
    $summary = $row['body_summary'];
    $created = formatDate($row['created']);
    $type = $row['type'];
    $username = $row['name'];

    $userDir  = $outputDir . $username;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0777, true);
    }



    // Ottieni i termini di tassonomia associati
    $taxonomyList = getTaxonomyTerms($pdo, $nid);

    // Ottieni il percorso dell'immagine di copertina
    $copertina = getCopertina($pdo, $nid);
    // Ottieni le immagini della gallery
    $galleryImages = getGalleryImages($pdo, $nid);
    // Genera la lista di immagini della gallery in YAML
    $galleryList = "";
    if ($galleryImages) {
        foreach ($galleryImages as $image) {
            $galleryList .= "- $image\n";
        }
    }

    // Nome del file basato sul titolo e nodo ID
    $filename = $userDir . "/" . preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)) . ".md";

    //Sostituisci nel body i link assoluti con relativi
    $body = str_replace("$site_url/files/", '', $body);
    // Converte il body da HTML a Markdown
    $bodyMarkdown = convertHtmlToMarkdown($body);
    $summaryMarkdown = convertHtmlToMarkdown($summary);


    // Contenuto del file Markdown
    $markdownContent = <<<MD
---
title: "{$title}"
type: "{$type}"
created: "{$created}"
nid: "{$nid}"
taxonomy: 
{$taxonomyList}
copertina: "{$copertina}"
gallery:
{$galleryList}
---

{$summaryMarkdown}

{$bodyMarkdown}
MD;

    // Scrive il contenuto nel file Markdown
    file_put_contents($filename, $markdownContent);
    echo "File creato: $filename\n";
}

echo "Tutti i contenuti sono stati esportati con successo in $outputDir\n";
