<?php
// --- 1. CONFIGURACIÓN SEGURA ---
$databaseUrl = getenv('DATABASE_URL');
if (!$databaseUrl) die("Error crítico: Falta DATABASE_URL");

$db = parse_url($databaseUrl);
$port = isset($db['port']) ? $db['port'] : '5432';
$dsn = "pgsql:" . sprintf("host=%s;port=%s;user=%s;password=%s;dbname=%s;sslmode=require",
    $db['host'], $port, $db['user'], $db['pass'], ltrim($db['path'], "/"));

$data = [];
$paises_para_filtro = []; 
$filtro_pais = isset($_GET['pais']) ? $_GET['pais'] : ''; 
$error = null;

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Lista de países para el select
    $stmtPaises = $pdo->query("SELECT DISTINCT pais FROM dim_pais ORDER BY pais");
    $paises_para_filtro = $stmtPaises->fetchAll(PDO::FETCH_COLUMN);

    // --- 2. CONSULTA OLAP ORDENADA ---
    $sql = "
        SELECT 
            p.pais as pais_real,         -- Guardamos el valor original (puede ser NULL)
            prod.categoria as cat_real,  -- Guardamos la categoría original
            COALESCE(p.pais, 'TOTAL GLOBAL') as pais_mostrar,
            COALESCE(prod.categoria, 'TODAS') as categoria_mostrar,
            COALESCE(SUM(v.total_dinero), 0) as venta_total, 
            COALESCE(SUM(v.cantidad), 0) as unidades
        FROM fact_ventas v
        JOIN dim_pais p ON v.pais_id = p.id
        JOIN dim_producto prod ON v.producto_id = prod.id
    ";

    $params = [];
    if ($filtro_pais && $filtro_pais !== 'TODOS') {
        $sql .= " WHERE p.pais = ? ";
        $params[] = $filtro_pais;
    }

    $sql .= " 
        GROUP BY CUBE(p.pais, prod.categoria)
        ORDER BY 
            -- TRUCO DE ORDENAMIENTO:
            -- 1. Primero agrupamos por País (Los NULL/Totales van al final)
            (CASE WHEN p.pais IS NULL THEN 1 ELSE 0 END), p.pais,
            -- 2. Luego por Categoría (Los NULL/Subtotales van al final)
            (CASE WHEN prod.categoria IS NULL THEN 1 ELSE 0 END), prod.categoria
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte OLAP Final</title>
    <style>
        /* Estilos limpios y modernos */
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f7f6; padding: 20px; color: #444; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #2c3e50; text-align: center; font-weight: 600;}
        
        .filter-box { background-color: #eef2f5; padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; justify-content: center; gap: 10px; }
        select { padding: 8px 12px; border-radius: 6px; border: 1px solid #ccc; font-size: 1rem; }
        button { background-color: #00D2A0; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: background 0.3s;}
        button:hover { background-color: #00b88d; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; border: 1px solid #eee; border-radius: 8px; overflow: hidden;}
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #f1f1f1; }
        th { background-color: #34495e; color: white; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;}
        
        /* Colores semánticos para el cubo */
        .fila-normal { background-color: #fff; }
        .fila-subtotal { background-color: #e8f5e9; color: #1b5e20; font-weight: bold; } /* Verde claro */
        .fila-total-global { background-color: #2c3e50; color: #fff; font-weight: bold; font-size: 1.1em; } /* Azul oscuro */
        
        tr:last-child td { border-bottom: none; }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 Reporte de Ventas Estructurado</h1>
    
    <div class="filter-box">
        <form method="GET" action="">
            <label for="pais">Filtrar por País: </label>
            <select name="pais" id="pais">
                <option value="TODOS">-- Ver Todo --</option>
                <?php foreach ($paises_para_filtro as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === $filtro_pais ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Actualizar</button>
        </form>
    </div>

    <?php if ($error): ?>
        <p style="color: red; text-align: center;"><?= $error ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>País</th>
                    <th>Categoría</th>
                    <th>Venta Total ($)</th>
                    <th>Unidades</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($data) > 0): ?>
                    <?php foreach ($data as $fila): ?>
                        <?php 
                            // Lógica para ocultar filas redundantes (Limpieza visual)
                            // Si filtramos por un país específico, las filas que dicen "TOTAL GLOBAL"
                            // (pero que no son el gran total final) son confusas. Las ocultamos.
                            $esFiltroActivo = ($filtro_pais && $filtro_pais !== 'TODOS');
                            $esTotalPais = ($fila['pais_real'] === null); // Es una fila de "Total Global"
                            $esGranTotal = ($fila['pais_real'] === null && $fila['cat_real'] === null);

                            // Si hay filtro activo, ocultamos los totales parciales globales (redundantes)
                            if ($esFiltroActivo && $esTotalPais && !$esGranTotal) {
                                continue; 
                            }

                            // Estilos
                            $claseCss = "fila-normal";
                            if ($esGranTotal) $claseCss = "fila-total-global";
                            elseif ($fila['cat_real'] === null) $claseCss = "fila-subtotal"; // Subtotal de País
                        ?>
                        <tr class="<?= $claseCss ?>">
                            <td><?= htmlspecialchars($fila['pais_mostrar']) ?></td>
                            <td><?= htmlspecialchars($fila['categoria_mostrar']) ?></td>
                            <td>$<?= number_format((float)$fila['venta_total'], 2) ?></td>
                            <td><?= $fila['unidades'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center; padding: 20px;">No hay datos disponibles.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
