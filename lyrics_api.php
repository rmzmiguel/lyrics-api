<?php
// lyrics_api.php (Actualizado)
// API para la aplicación de letras de canciones

// Configuración de la base de datos
$host = 'localhost';
$db = 'lyrics_app_db';  
$user = 'root';        
$password = 'root';    
$port = 8888;          

// Cabeceras para permitir solicitudes desde la app
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Función para registrar errores
function logError($message, $data = null) {
    $logFile = 'api_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    if ($data !== null) {
        $logMessage .= "Data: " . json_encode($data) . "\n";
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Conectar a la base de datos
try {
    $conn = new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    logError("Error de conexión a la base de datos: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

// Función para obtener las secciones de una canción
function getSongSections($conn, $songId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM song_sections WHERE song_id = :song_id ORDER BY section_order");
        $stmt->bindParam(':song_id', $songId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        logError("Error al obtener las secciones de la canción: " . $e->getMessage());
        return [];
    }
}

// Función para guardar las secciones de una canción
function saveSongSections($conn, $songId, $sections) {
    try {
        // Primero eliminamos todas las secciones existentes
        $stmt = $conn->prepare("DELETE FROM song_sections WHERE song_id = :song_id");
        $stmt->bindParam(':song_id', $songId);
        $stmt->execute();
        
        // Luego insertamos las nuevas secciones
        foreach ($sections as $section) {
            $stmt = $conn->prepare("INSERT INTO song_sections (song_id, section_id, section_type, content, section_order) 
                                   VALUES (:song_id, :section_id, :section_type, :content, :section_order)");
            $stmt->bindParam(':song_id', $songId);
            $stmt->bindParam(':section_id', $section['id']);
            $stmt->bindParam(':section_type', $section['type']);
            $stmt->bindParam(':content', $section['content']);
            $stmt->bindParam(':section_order', $section['order']);
            $stmt->execute();
        }
        return true;
    } catch(PDOException $e) {
        logError("Error al guardar las secciones de la canción: " . $e->getMessage());
        return false;
    }
}

// Obtener el método de solicitud
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);
$endpoint = end($uri);

// Log de la solicitud
$requestData = file_get_contents('php://input');
logError("Solicitud recibida - Método: $method, Endpoint: $endpoint", json_decode($requestData, true));

// Rutas de la API
switch($method) {
    case 'GET':
        // Obtener todas las canciones
        if ($endpoint == 'songs') {
            try {
                $stmt = $conn->prepare("SELECT id, title, artist, genre, bpm, time_signature, key_signature, duration, cover_image, created_at FROM songs ORDER BY created_at DESC");
                $stmt->execute();
                $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($songs as &$song) {
                    $songId = $song['id'];

                    // Cargar secciones para esta canción
                    $stmtSections = $conn->prepare("SELECT section_id AS id, section_type AS type, content, section_order AS `order` FROM song_sections WHERE song_id = :id ORDER BY section_order ASC");
                    $stmtSections->bindParam(':id', $songId);
                    $stmtSections->execute();
                    $sections = $stmtSections->fetchAll(PDO::FETCH_ASSOC);

                    // Cast de tipo entero para orden
                    foreach ($sections as &$section) {
                        $section['order'] = (int)$section['order'];
                    }

                    $song['bpm'] = (int)$song['bpm'];
                    $song['sections'] = $sections;
                }

                
                echo json_encode(['status' => 'success', 'data' => $songs]);
            } catch(PDOException $e) {
                logError("Error al obtener canciones: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        
        // Obtener una canción por ID
        else if (preg_match('/^song-(\d+)$/', $endpoint, $matches)) {
            try {
                $songId = $matches[1];
                $stmt = $conn->prepare("SELECT id, title, artist, genre, bpm, time_signature, key_signature, duration, cover_image, verse, pre_chorus, chorus, bridge, created_at FROM songs WHERE id = :id");
                $stmt->bindParam(':id', $songId);
                $stmt->execute();
                $song = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($song) {
                    // Obtener las secciones para esta canción
                    $song['sections'] = getSongSections($conn, $songId);
                    echo json_encode(['status' => 'success', 'data' => $song]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Canción no encontrada']);
                }
            } catch(PDOException $e) {
                logError("Error al obtener canción por ID: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        break;
    
    case 'POST':
        // Datos recibidos en formato JSON
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            logError("Error: JSON inválido recibido", $requestData);
            echo json_encode(['status' => 'error', 'message' => 'JSON inválido']);
            break;
        }
        
        // Añadir nueva canción
        if ($endpoint == 'add-song') {
            try {
                // Validar campos obligatorios
                if (empty($data['title']) || empty($data['artist']) || empty($data['genre']) || 
                    !isset($data['bpm']) || empty($data['time_signature']) || empty($data['duration'])) {
                    
                    logError("Error: Faltan campos obligatorios", $data);
                    echo json_encode(['status' => 'error', 'message' => 'Faltan campos obligatorios']);
                    break;
                }
                
                $title = $data['title'];
                $artist = $data['artist'];
                $genre = $data['genre'];
                $bpm = (int)$data['bpm']; // Asegurar que es un entero
                $timeSignature = $data['time_signature'];
                $keySignature = isset($data['key_signature']) ? $data['key_signature'] : 'C';
                $duration = $data['duration'];
                $coverImage = isset($data['cover_image']) ? $data['cover_image'] : null;
                $verse = isset($data['verse']) ? $data['verse'] : null;
                $preChorus = isset($data['pre_chorus']) ? $data['pre_chorus'] : null;
                $chorus = isset($data['chorus']) ? $data['chorus'] : null;
                $bridge = isset($data['bridge']) ? $data['bridge'] : null;
                
                $stmt = $conn->prepare("INSERT INTO songs (title, artist, genre, bpm, time_signature, key_signature, duration, cover_image, verse, pre_chorus, chorus, bridge) 
                                       VALUES (:title, :artist, :genre, :bpm, :time_signature, :key_signature, :duration, :cover_image, :verse, :pre_chorus, :chorus, :bridge)");
                
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':artist', $artist);
                $stmt->bindParam(':genre', $genre);
                $stmt->bindParam(':bpm', $bpm);
                $stmt->bindParam(':time_signature', $timeSignature);
                $stmt->bindParam(':key_signature', $keySignature);
                $stmt->bindParam(':duration', $duration);
                $stmt->bindParam(':cover_image', $coverImage);
                $stmt->bindParam(':verse', $verse);
                $stmt->bindParam(':pre_chorus', $preChorus);
                $stmt->bindParam(':chorus', $chorus);
                $stmt->bindParam(':bridge', $bridge);
                
                $stmt->execute();
                $lastId = $conn->lastInsertId();
                
                // Guardar secciones si están presentes
                if (isset($data['sections']) && is_array($data['sections']) && count($data['sections']) > 0) {
                    saveSongSections($conn, $lastId, $data['sections']);
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Canción añadida correctamente', 'songId' => (int)$lastId]);
            } catch(PDOException $e) {
                logError("Error al añadir canción: " . $e->getMessage(), $data);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        
        // Actualizar canción existente
        else if ($endpoint == 'update-song') {
            try {
                // Validar campos obligatorios
                if (!isset($data['id']) || empty($data['title']) || empty($data['artist']) || 
                    empty($data['genre']) || !isset($data['bpm']) || empty($data['time_signature']) || 
                    empty($data['duration'])) {
                    
                    logError("Error: Faltan campos obligatorios para actualización", $data);
                    echo json_encode(['status' => 'error', 'message' => 'Faltan campos obligatorios']);
                    break;
                }
                
                $id = $data['id'];
                $title = $data['title'];
                $artist = $data['artist'];
                $genre = $data['genre'];
                $bpm = (int)$data['bpm']; // Asegurar que es un entero
                $timeSignature = $data['time_signature'];
                $keySignature = isset($data['key_signature']) ? $data['key_signature'] : 'C';
                $duration = $data['duration'];
                $coverImage = isset($data['cover_image']) ? $data['cover_image'] : null;
                $verse = isset($data['verse']) ? $data['verse'] : null;
                $preChorus = isset($data['pre_chorus']) ? $data['pre_chorus'] : null;
                $chorus = isset($data['chorus']) ? $data['chorus'] : null;
                $bridge = isset($data['bridge']) ? $data['bridge'] : null;
                
                // Si coverImage es null, evitar actualizar ese campo
                if ($coverImage === null) {
                    $stmt = $conn->prepare("UPDATE songs SET 
                                          title = :title, 
                                          artist = :artist, 
                                          genre = :genre, 
                                          bpm = :bpm, 
                                          time_signature = :time_signature, 
                                          key_signature = :key_signature, 
                                          duration = :duration, 
                                          verse = :verse, 
                                          pre_chorus = :pre_chorus, 
                                          chorus = :chorus, 
                                          bridge = :bridge 
                                          WHERE id = :id");
                } else {
                    $stmt = $conn->prepare("UPDATE songs SET 
                                          title = :title, 
                                          artist = :artist, 
                                          genre = :genre, 
                                          bpm = :bpm, 
                                          time_signature = :time_signature, 
                                          key_signature = :key_signature, 
                                          duration = :duration, 
                                          cover_image = :cover_image, 
                                          verse = :verse, 
                                          pre_chorus = :pre_chorus, 
                                          chorus = :chorus, 
                                          bridge = :bridge 
                                          WHERE id = :id");
                    $stmt->bindParam(':cover_image', $coverImage);
                }
                
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':artist', $artist);
                $stmt->bindParam(':genre', $genre);
                $stmt->bindParam(':bpm', $bpm);
                $stmt->bindParam(':time_signature', $timeSignature);
                $stmt->bindParam(':key_signature', $keySignature);
                $stmt->bindParam(':duration', $duration);
                $stmt->bindParam(':verse', $verse);
                $stmt->bindParam(':pre_chorus', $preChorus);
                $stmt->bindParam(':chorus', $chorus);
                $stmt->bindParam(':bridge', $bridge);
                
                $stmt->execute();
                
                // Actualizar secciones si están presentes
                if (isset($data['sections']) && is_array($data['sections'])) {
                    saveSongSections($conn, $id, $data['sections']);
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Canción actualizada correctamente']);
            } catch(PDOException $e) {
                logError("Error al actualizar canción: " . $e->getMessage(), $data);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        
        // Eliminar canción
        else if ($endpoint == 'delete-song') {
            try {
                // Validar ID
                if (!isset($data['id'])) {
                    logError("Error: Falta ID para eliminar canción", $data);
                    echo json_encode(['status' => 'error', 'message' => 'ID de canción no proporcionado']);
                    break;
                }
                
                $id = $data['id'];
                
                // Eliminar primero las secciones (esto no es necesario si usaste ON DELETE CASCADE en la restricción de clave externa)
                $stmt = $conn->prepare("DELETE FROM song_sections WHERE song_id = :id");
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                // Eliminar la canción
                $stmt = $conn->prepare("DELETE FROM songs WHERE id = :id");
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                echo json_encode(['status' => 'success', 'message' => 'Canción eliminada correctamente']);
            } catch(PDOException $e) {
                logError("Error al eliminar canción: " . $e->getMessage(), $data);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            logError("Endpoint no válido: $endpoint");
            echo json_encode(['status' => 'error', 'message' => 'Endpoint no válido']);
        }
        break;
        
    default:
        logError("Método no permitido: $method");
        echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
        break;
}
?>
