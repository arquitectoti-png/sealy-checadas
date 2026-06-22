import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

const defaultApiBaseUrl = 'https://staraz.site/sealy/api';

void main() {
  runApp(const SealyApp());
}

class SealyApp extends StatelessWidget {
  const SealyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Sealy',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF145DF5)),
        useMaterial3: true,
      ),
      home: const AppShell(),
    );
  }
}

class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  final _apiController = TextEditingController(text: defaultApiBaseUrl);
  final _loginController = TextEditingController();
  final _passwordController = TextEditingController();
  final _currentPasswordController = TextEditingController();
  final _newPasswordController = TextEditingController();

  SharedPreferences? _prefs;
  String? _token;
  AppUser? _user;
  MobileBootstrap? _bootstrap;
  int _tabIndex = 0;
  bool _busy = false;
  String _phase = 'ingreso';
  String _checkMessage = 'Listo para iniciar.';
  String _recordsMessage = 'Sin registros cargados.';
  String _syncMessage = 'Sin sincronizaciones recientes.';
  String _passwordMessage = '';
  String _noticesMessage = 'Sin avisos cargados.';
  List<PendingCheck> _queue = [];
  List<CheckRecord> _records = [];
  List<NoticeItem> _notices = [];
  NearestStore? _lastNearestStore;

  @override
  void initState() {
    super.initState();
    _loadState();
  }

  @override
  void dispose() {
    _apiController.dispose();
    _loginController.dispose();
    _passwordController.dispose();
    _currentPasswordController.dispose();
    _newPasswordController.dispose();
    super.dispose();
  }

  Future<void> _loadState() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');
    final apiBase = prefs.getString('apiBaseUrl');
    final userJson = prefs.getString('user');
    final bootstrapJson = prefs.getString('bootstrap');
    final queueJson = prefs.getString('queue');

    setState(() {
      _prefs = prefs;
      _token = token;
      if (apiBase != null && apiBase.isNotEmpty) {
        _apiController.text = apiBase;
      }
      if (userJson != null) {
        _user = AppUser.fromJson(jsonDecode(userJson) as Map<String, dynamic>);
      }
      if (bootstrapJson != null) {
        _bootstrap = MobileBootstrap.fromJson(
            jsonDecode(bootstrapJson) as Map<String, dynamic>);
      }
      if (queueJson != null) {
        final list = jsonDecode(queueJson) as List<dynamic>;
        _queue = list
            .map((item) => PendingCheck.fromJson(item as Map<String, dynamic>))
            .toList();
        _purgeExpiredRejectedChecks();
      }
    });
  }

  String get _apiBaseUrl =>
      _apiController.text.trim().replaceAll(RegExp(r'/+$'), '');

  bool get _isLoggedIn => _token != null && _user != null;
  List<PendingCheck> get _pendingQueue =>
      _queue.where((item) => item.rejectedAt == null).toList();
  List<PendingCheck> get _rejectedQueue =>
      _queue.where((item) => item.rejectedAt != null).toList();

  Future<bool> _isOnline() async {
    try {
      final uri = Uri.parse(_apiBaseUrl);
      final host = uri.host.isEmpty ? 'google.com' : uri.host;
      final result = await InternetAddress.lookup(host)
          .timeout(const Duration(seconds: 5));
      return result.isNotEmpty && result.first.rawAddress.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  Future<Map<String, dynamic>> _request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    bool allow422 = false,
  }) async {
    final uri = Uri.parse('$_apiBaseUrl$path');
    final headers = <String, String>{
      'Content-Type': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    };

    http.Response response;
    try {
      if (method == 'POST') {
        response = await http
            .post(uri, headers: headers, body: jsonEncode(body ?? {}))
            .timeout(const Duration(seconds: 30));
      } else {
        response = await http
            .get(uri, headers: headers)
            .timeout(const Duration(seconds: 30));
      }
    } on SocketException {
      throw ApiException(
          'Sin datos o sin internet. Conectate e intenta de nuevo.', 0);
    } on TimeoutException {
      throw ApiException(
          'La conexion tardo demasiado. Revisa tus datos o internet.', 0);
    } on http.ClientException {
      throw ApiException(
          'No se pudo conectar con el servidor. Revisa tus datos o internet.',
          0);
    }

    Map<String, dynamic> decoded;
    try {
      final parsed = response.body.isEmpty
          ? <String, dynamic>{}
          : jsonDecode(response.body);
      if (parsed is! Map<String, dynamic>) {
        throw const FormatException('Respuesta no valida');
      }
      decoded = parsed;
    } on FormatException {
      throw ApiException(
          'Sin datos o servidor no disponible. Conectate e intenta de nuevo.',
          response.statusCode);
    }

    final ok = response.statusCode >= 200 && response.statusCode < 300;
    if (!ok && !(allow422 && response.statusCode == 422)) {
      throw ApiException(
        decoded['message']?.toString() ??
            decoded['error']?.toString() ??
            'No se pudo completar la solicitud ${response.statusCode}',
        response.statusCode,
      );
    }
    return decoded;
  }

  Future<void> _login() async {
    await _runBusy(() async {
      final data = await _request('/auth/login', method: 'POST', body: {
        'login': _loginController.text.trim(),
        'password': _passwordController.text,
        'client_type': 'mobile',
      });

      _token = data['token'] as String;
      _user = AppUser.fromJson(data['user'] as Map<String, dynamic>);
      await _prefs?.setString('apiBaseUrl', _apiBaseUrl);
      await _prefs?.setString('token', _token!);
      await _prefs?.setString('user', jsonEncode(_user!.toJson()));
      await _loadBootstrap();
      _checkMessage = 'Sesion iniciada.';
    });
  }

  Future<void> _logout() async {
    await _prefs?.remove('token');
    await _prefs?.remove('user');
    setState(() {
      _token = null;
      _user = null;
      _bootstrap = null;
      _records = [];
      _checkMessage = 'Sesion cerrada.';
      _recordsMessage = 'Sin registros cargados.';
      _syncMessage = 'Sin sincronizaciones recientes.';
      _passwordMessage = '';
      _noticesMessage = 'Sin avisos cargados.';
    });
  }

  Future<void> _changePassword() async {
    await _runBusy(() async {
      if (_newPasswordController.text.length < 8) {
        throw ApiException(
            'La nueva contraseña debe tener al menos 8 caracteres.', 400);
      }
      await _request('/me/change-password', method: 'POST', body: {
        'current_password': _currentPasswordController.text,
        'new_password': _newPasswordController.text,
      });
      _currentPasswordController.clear();
      _newPasswordController.clear();
      _passwordMessage = 'Contraseña actualizada.';
    });
  }

  Future<void> _loadBootstrap() async {
    final data =
        await _request('/me/mobile-bootstrap?date=${_localDateOnly()}');
    final bootstrap = MobileBootstrap.fromJson(data);
    setState(() {
      _bootstrap = bootstrap;
      _syncSelectedPhaseWithToday();
    });
    await _prefs?.setString('bootstrap', jsonEncode(bootstrap.toJson()));
  }

  Future<void> _makeCheck() async {
    await _runBusy(() async {
      final availablePhases = _availablePhaseOptions;
      final selectablePhases =
          availablePhases.where((item) => item.enabled).toList();
      if (selectablePhases.isEmpty) {
        _checkMessage = 'Ya registraste las checadas requeridas de hoy.';
        return;
      }
      if (!selectablePhases.any((item) => item.key == _phase)) {
        _phase = selectablePhases.first.key;
      }
      final position = await _currentPosition();
      final mockLocation = position.isMocked;
      if (mockLocation) {
        _checkMessage =
            'Ubicacion simulada detectada. Desactiva apps de GPS falso.';
        return;
      }
      if (position.accuracy > 75) {
        _checkMessage =
            'GPS impreciso (${position.accuracy.toStringAsFixed(1)} m). Intenta en area abierta.';
        return;
      }
      if (_deviceClockLooksSuspicious()) {
        _checkMessage =
            'Hora del dispositivo sospechosa. Activa fecha y hora automaticas.';
        return;
      }
      final nearest = _nearestStore(position);
      if (nearest == null) {
        throw ApiException('No hay tiendas activas disponibles.', 400);
      }
      _lastNearestStore = nearest;
      final store = nearest.store;
      final clientDistance = nearest.distanceMeters;

      if (clientDistance > store.allowedRadiusMeters) {
        _checkMessage =
            'Bloqueado: tienda más cercana ${store.name} a ${clientDistance.toStringAsFixed(1)} m. Radio permitido ${store.allowedRadiusMeters} m.';
        setState(() {});
        return;
      }

      final image = await _captureFrontCameraPhoto();
      if (image == null) {
        throw ApiException('La foto es obligatoria.', 400);
      }

      final photoBase64 =
          'data:image/jpeg;base64,${base64Encode(await image.readAsBytes())}';
      final pending = PendingCheck(
        localId: DateTime.now().microsecondsSinceEpoch.toString(),
        storeId: store.id,
        phase: _phase,
        capturedAtDevice: DateTime.now().toIso8601String(),
        latitude: position.latitude,
        longitude: position.longitude,
        gpsAccuracyMeters: position.accuracy,
        gpsIsMocked: mockLocation,
        distanceMetersClient: clientDistance,
        deviceId: await _deviceId(),
        photoBase64: photoBase64,
      );

      if (!await _isOnline()) {
        await _addToQueue(pending);
        _checkMessage = 'Checada guardada offline. Pendiente de sincronizar.';
        return;
      }

      Map<String, dynamic> data;
      try {
        data = await _request(
          '/checks',
          method: 'POST',
          body: pending.toApiJson(),
          allow422: true,
        );
      } on ApiException catch (err) {
        if (err.statusCode == 0) {
          await _addToQueue(pending);
          _checkMessage = 'Checada guardada offline. Pendiente de sincronizar.';
          return;
        }
        rethrow;
      }

      if (data['status'] == 'blocked_out_of_range') {
        _checkMessage =
            'Bloqueado: ${_readDouble(data['distance_meters']).toStringAsFixed(1)} m de la tienda. Radio ${data['allowed_radius_meters']} m.';
      } else {
        _checkMessage =
            'Checada registrada. Distancia: ${_readDouble(data['distance_meters']).toStringAsFixed(1)} m.';
        await _loadBootstrap();
      }
    });
  }

  Future<void> _syncQueue() async {
    await _runBusy(() async {
      if (_queue.isEmpty) {
        _syncMessage = 'No hay pendientes por sincronizar.';
        return;
      }
      if (!await _isOnline()) {
        _syncMessage = 'Sin conexion. La cola sigue pendiente.';
        return;
      }

      _purgeExpiredRejectedChecks();
      final syncable = _queue.where((item) => item.rejectedAt == null).toList();
      if (syncable.isEmpty) {
        await _saveQueue();
        _syncMessage =
            'No hay pendientes por reenviar. Los rechazados se conservaran 48 horas.';
        return;
      }

      final data = await _request('/checks/sync', method: 'POST', body: {
        'device_id': await _deviceId(),
        'items': syncable.map((item) => item.toSyncJson()).toList(),
      });

      final syncedIds = ((data['synced'] ?? []) as List<dynamic>)
          .map((item) => (item as Map<String, dynamic>)['local_id']?.toString())
          .whereType<String>()
          .toSet();

      final rejectedItems = ((data['rejected'] ?? []) as List<dynamic>)
          .map((item) => item as Map<String, dynamic>)
          .toList();
      final rejectedById = {
        for (final item in rejectedItems)
          if (item['local_id'] != null)
            item['local_id'].toString():
                item['message']?.toString() ?? 'Rechazada'
      };

      _queue = _queue
          .where((item) => !syncedIds.contains(item.localId))
          .map((item) => rejectedById.containsKey(item.localId)
              ? item.markRejected(rejectedById[item.localId]!)
              : item)
          .toList();
      _purgeExpiredRejectedChecks();
      await _saveQueue();
      await _loadBootstrap();

      _syncMessage =
          'Sincronizados: ${syncedIds.length}. Rechazados: ${rejectedItems.length}.';
    });
  }

  Future<void> _loadRecords() async {
    await _runBusy(() async {
      if (!await _isOnline()) {
        _recordsMessage =
            'Sin datos o sin internet. Conectate para actualizar el historial.';
        return;
      }
      final now = DateTime.now();
      final start =
          '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-01';
      final end =
          '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
      final data = await _request('/checks/my?start=$start&end=$end');
      final items = (data['items'] ?? []) as List<dynamic>;
      _records = items
          .map((item) => CheckRecord.fromJson(item as Map<String, dynamic>))
          .toList();
      _recordsMessage = 'Historial actualizado.';
    });
  }

  Future<void> _loadNotices() async {
    await _runBusy(() async {
      if (!await _isOnline()) {
        _noticesMessage =
            'Sin datos o sin internet. Conectate para actualizar avisos.';
        return;
      }
      final data = await _request('/me/notices');
      final items = (data['items'] ?? []) as List<dynamic>;
      _notices = items
          .map((item) => NoticeItem.fromJson(item as Map<String, dynamic>))
          .toList();
      _noticesMessage = _notices.isEmpty
          ? 'No hay avisos disponibles.'
          : 'Avisos actualizados.';
    });
  }

  Future<void> _addToQueue(PendingCheck check) async {
    _queue = [..._queue, check];
    await _saveQueue();
    setState(() {});
  }

  Future<void> _saveQueue() async {
    _purgeExpiredRejectedChecks();
    await _prefs?.setString(
        'queue', jsonEncode(_queue.map((item) => item.toJson()).toList()));
    setState(() {});
  }

  void _purgeExpiredRejectedChecks() {
    final cutoff = DateTime.now().subtract(const Duration(hours: 48));
    _queue = _queue.where((item) {
      final rejectedAt = item.rejectedAt;
      return rejectedAt == null || rejectedAt.isAfter(cutoff);
    }).toList();
  }

  Future<String> _deviceId() async {
    var id = _prefs?.getString('deviceId');
    if (id == null || id.isEmpty) {
      id = DateTime.now().microsecondsSinceEpoch.toString();
      await _prefs?.setString('deviceId', id);
    }
    return id;
  }

  Future<Position> _currentPosition() async {
    final enabled = await Geolocator.isLocationServiceEnabled();
    if (!enabled) {
      throw ApiException('Activa el GPS para poder checar.', 400);
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw ApiException('Permiso de ubicacion requerido.', 400);
    }

    return Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );
  }

  Future<XFile?> _captureFrontCameraPhoto() {
    return Navigator.of(context).push<XFile>(
      MaterialPageRoute(builder: (_) => const FrontCameraCapturePage()),
    );
  }

  bool _deviceClockLooksSuspicious() {
    final serverTime = _bootstrap?.serverTime;
    if (serverTime == null) {
      return false;
    }
    final parsed = DateTime.tryParse(serverTime);
    if (parsed == null) {
      return false;
    }
    return DateTime.now().isBefore(parsed.subtract(const Duration(minutes: 5)));
  }

  Future<void> _runBusy(Future<void> Function() action) async {
    setState(() {
      _busy = true;
    });
    try {
      await action();
    } catch (err) {
      final message = err.toString().replaceFirst('Exception: ', '');
      if (_tabIndex == 1) {
        _recordsMessage = message;
      } else if (_tabIndex == 2) {
        _syncMessage = message;
      } else if (_tabIndex == 3) {
        _passwordMessage = message;
      } else if (_tabIndex == 4) {
        _noticesMessage = message;
      } else {
        _checkMessage = message;
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_prefs == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset('assets/sealy.png',
                width: 34, height: 34, fit: BoxFit.contain),
            const SizedBox(width: 10),
            const Text('Sealy'),
          ],
        ),
        actions: [
          if (_isLoggedIn)
            IconButton(
              tooltip: 'Cerrar sesion',
              onPressed: _busy ? null : _logout,
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: _isLoggedIn ? _buildHome() : _buildLogin(),
      bottomNavigationBar: _isLoggedIn
          ? NavigationBar(
              selectedIndex: _tabIndex,
              onDestinationSelected: (index) {
                setState(() => _tabIndex = index);
                if (index == 1 && _records.isEmpty && !_busy) {
                  _loadRecords();
                } else if (index == 4 && _notices.isEmpty && !_busy) {
                  _loadNotices();
                }
              },
              destinations: const [
                NavigationDestination(
                    icon: Icon(Icons.camera_alt_outlined), label: 'Checar'),
                NavigationDestination(
                    icon: Icon(Icons.history), label: 'Registros'),
                NavigationDestination(icon: Icon(Icons.sync), label: 'Sync'),
                NavigationDestination(
                    icon: Icon(Icons.lock_outline), label: 'Clave'),
                NavigationDestination(
                    icon: Icon(Icons.campaign_outlined), label: 'Avisos'),
              ],
            )
          : null,
    );
  }

  Widget _buildLogin() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _InfoCard(
          title: 'Ingreso personal',
          child: Column(
            children: [
              Image.asset('assets/sealy.png', height: 110, fit: BoxFit.contain),
              const SizedBox(height: 16),
              TextField(
                controller: _loginController,
                decoration: const InputDecoration(
                    labelText: 'Correo, telefono o numero empleado'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _passwordController,
                decoration: const InputDecoration(labelText: 'Contrasena'),
                obscureText: true,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _busy ? null : _login,
                icon: const Icon(Icons.login),
                label: const Text('Ingresar'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Text(_checkMessage),
      ],
    );
  }

  Widget _buildHome() {
    final pages = [
      _buildCheckTab(),
      _buildRecordsTab(),
      _buildSyncTab(),
      _buildPasswordTab(),
      _buildNoticesTab()
    ];
    return Stack(
      children: [
        pages[_tabIndex],
        if (_busy)
          Container(
            color: Colors.black.withValues(alpha: 0.08),
            child: const Center(child: CircularProgressIndicator()),
          ),
      ],
    );
  }

  Widget _buildCheckTab() {
    final availablePhases = _availablePhaseOptions;
    return RefreshIndicator(
      onRefresh: _loadBootstrap,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _InfoCard(
            title: _user?.fullName ?? 'Personal',
            child: Text(_storeSummaryText()),
          ),
          _InfoCard(
            title: 'Nueva checada',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (availablePhases.isEmpty)
                  const Text('Ya registraste las checadas requeridas de hoy.')
                else
                  DropdownButtonFormField<String>(
                    initialValue:
                        availablePhases.any((item) => item.key == _phase)
                            ? _phase
                            : availablePhases.first.key,
                    decoration: const InputDecoration(labelText: 'Fase'),
                    items: availablePhases
                        .map(
                          (item) => DropdownMenuItem(
                            value: item.key,
                            enabled: item.enabled,
                            child: Text(item.enabled
                                ? item.label
                                : '${item.label} - pendiente anterior'),
                          ),
                        )
                        .toList(),
                    onChanged: (value) {
                      if (value != null) {
                        setState(() => _phase = value);
                      }
                    },
                  ),
                const SizedBox(height: 12),
                FilledButton.icon(
                  onPressed:
                      _busy || availablePhases.isEmpty ? null : _makeCheck,
                  icon: const Icon(Icons.camera_alt),
                  label: const Text('Tomar foto y checar'),
                ),
                const SizedBox(height: 8),
                Text(_checkMessage),
              ],
            ),
          ),
          _InfoCard(
            title: 'Checadas de hoy',
            child: _buildTodayChecks(),
          ),
        ],
      ),
    );
  }

  List<PhaseOption> get _phaseOptions => [
        const PhaseOption('ingreso', 'Ingreso'),
        const PhaseOption('salida_comer', 'Salida a comer',
            requiredPrevious: 'ingreso'),
        const PhaseOption('entrada_comer', 'Entrada de comer',
            requiredPrevious: 'salida_comer'),
        const PhaseOption('salida', 'Salida',
            requiredPrevious: 'entrada_comer'),
        if (_bootstrap?.requiresLocationVerification == true) ...const [
          PhaseOption('verificacion_ubicacion_1', 'Verificación de ubicación 1',
              verification: true),
          PhaseOption('verificacion_ubicacion_2', 'Verificación de ubicación 2',
              verification: true),
          PhaseOption('verificacion_ubicacion_3', 'Verificación de ubicación 3',
              verification: true),
        ],
      ];

  Set<String> get _checkedPhasesToday {
    final checked = _todayChecks.map((check) => check.phase).toSet();
    checked.addAll(_pendingQueue
        .where((item) => _isToday(item.capturedAtDevice))
        .map((item) => item.phase));
    return checked;
  }

  String _localDateOnly([DateTime? value]) {
    final date = value ?? DateTime.now();
    final month = date.month.toString().padLeft(2, '0');
    final day = date.day.toString().padLeft(2, '0');
    return '${date.year}-$month-$day';
  }

  bool _isToday(String isoDate) {
    final parsed = DateTime.tryParse(isoDate);
    if (parsed == null) {
      return false;
    }
    final now = DateTime.now();
    return parsed.year == now.year &&
        parsed.month == now.month &&
        parsed.day == now.day;
  }

  List<TodayCheck> get _todayChecks {
    final today = _localDateOnly();
    return (_bootstrap?.todayChecks ?? [])
        .where((check) => check.checkDate.isNotEmpty
            ? check.checkDate == today
            : _isToday(check.checkedAt))
        .toList();
  }

  List<PhaseOption> get _availablePhaseOptions {
    final checked = _checkedPhasesToday;
    return _phaseOptions
        .where((item) => !checked.contains(item.key))
        .map((item) => item.copyWith(
            enabled: item.requiredPrevious == null ||
                checked.contains(item.requiredPrevious)))
        .toList();
  }

  void _syncSelectedPhaseWithToday() {
    final available = _availablePhaseOptions;
    final selectable = available.where((item) => item.enabled).toList();
    if (selectable.isNotEmpty &&
        (!selectable.any((item) => item.key == _phase) ||
            _checkedPhasesToday.contains(_phase))) {
      _phase = selectable.first.key;
    }
  }

  Widget _buildTodayChecks() {
    final checks = _todayChecks;
    if (checks.isEmpty) {
      return const Text('Sin checadas registradas hoy.');
    }
    return Column(
      children: checks
          .map(
            (check) => ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.check_circle_outline),
              title: Text(_phaseLabel(check.phase)),
              subtitle:
                  Text('${check.checkedAt} - ${_statusLabel(check.status)}'),
            ),
          )
          .toList(),
    );
  }

  Widget _buildRecordsTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FilledButton.icon(
          onPressed: _busy ? null : _loadRecords,
          icon: const Icon(Icons.refresh),
          label: const Text('Actualizar historial'),
        ),
        const SizedBox(height: 12),
        Text(_recordsMessage),
        const SizedBox(height: 12),
        if (_records.isEmpty)
          const _InfoCard(
              title: 'Historial', child: Text('Sin registros cargados.'))
        else
          ..._records.map(
            (record) => _InfoCard(
              title: '${record.checkDate} - ${_phaseLabel(record.phase)}',
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${record.storeLabel} - ${record.distanceMeters.toStringAsFixed(1)} m - ${_statusLabel(record.status)}\n'
                    'Laborado: ${record.workedTime} / Comida: ${record.mealTime}',
                  ),
                  if (record.photoUrl.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: _token == null
                          ? null
                          : () => _showRecordPhoto(record),
                      icon: const Icon(Icons.photo),
                      label: const Text('Ver foto'),
                    ),
                  ],
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildSyncTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _InfoCard(
          title: 'Cola pendiente',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('${_pendingQueue.length} checada(s) pendientes.'),
              const SizedBox(height: 8),
              const Text(
                  'Usa esta seccion solo cuando necesites reenviar checadas pendientes.'),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: _busy ? null : _syncQueue,
                icon: const Icon(Icons.sync),
                label: const Text('Sincronizar ahora'),
              ),
              const SizedBox(height: 8),
              Text(_syncMessage),
            ],
          ),
        ),
        ..._pendingQueue.map(
          (item) => _InfoCard(
            title: _phaseLabel(item.phase),
            child: Text(
                '${item.capturedAtDevice}\nDistancia cliente: ${item.distanceMetersClient.toStringAsFixed(1)} m'),
          ),
        ),
        ..._rejectedQueue.map(
          (item) => _InfoCard(
            title: '${_phaseLabel(item.phase)} - Rechazada',
            child: Text(
              '${item.capturedAtDevice}\n'
              'Distancia cliente: ${item.distanceMetersClient.toStringAsFixed(1)} m\n'
              '${item.rejectedMessage ?? "Rechazada"}\n'
              'Se ocultara automaticamente despues de 48 horas.',
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildPasswordTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _InfoCard(
          title: 'Cambiar contraseña',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                controller: _currentPasswordController,
                decoration:
                    const InputDecoration(labelText: 'Contraseña actual'),
                obscureText: true,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _newPasswordController,
                decoration:
                    const InputDecoration(labelText: 'Nueva contraseña'),
                obscureText: true,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _busy ? null : _changePassword,
                icon: const Icon(Icons.lock_reset),
                label: const Text('Actualizar contraseña'),
              ),
              const SizedBox(height: 8),
              Text(_passwordMessage),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildNoticesTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FilledButton.icon(
          onPressed: _busy ? null : _loadNotices,
          icon: const Icon(Icons.refresh),
          label: const Text('Actualizar avisos'),
        ),
        const SizedBox(height: 12),
        Text(_noticesMessage),
        const SizedBox(height: 12),
        if (_notices.isEmpty)
          const _InfoCard(
              title: 'Avisos', child: Text('Sin avisos disponibles.'))
        else
          ..._notices.map(
            (notice) => _InfoCard(
              title: notice.title,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (notice.body.isNotEmpty) Text(notice.body),
                  if (notice.imageUrl.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Image.network(
                      '$_apiBaseUrl${notice.imageUrl}',
                      headers: {'Authorization': 'Bearer $_token'},
                      fit: BoxFit.contain,
                      errorBuilder: (_, __, ___) =>
                          const Text('No se pudo cargar la imagen.'),
                    ),
                  ],
                  const SizedBox(height: 8),
                  Text(notice.publishedAt,
                      style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
          ),
      ],
    );
  }

  NearestStore? _nearestStore(Position position) {
    final stores = _bootstrap?.activeStores ?? [];
    if (stores.isEmpty) {
      return null;
    }
    NearestStore? nearest;
    for (final store in stores) {
      final distance = distanceMeters(
        position.latitude,
        position.longitude,
        store.latitude,
        store.longitude,
      );
      final candidate = NearestStore(store: store, distanceMeters: distance);
      if (nearest == null ||
          candidate.distanceMeters < nearest.distanceMeters) {
        nearest = candidate;
      }
    }
    return nearest;
  }

  void _showRecordPhoto(CheckRecord record) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Align(
                alignment: Alignment.centerRight,
                child: IconButton(
                  tooltip: 'Cerrar',
                  onPressed: () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close),
                ),
              ),
              Image.network(
                '$_apiBaseUrl${record.photoUrl}',
                headers: {'Authorization': 'Bearer $_token'},
                fit: BoxFit.contain,
                loadingBuilder: (context, child, progress) {
                  if (progress == null) return child;
                  return const Padding(
                    padding: EdgeInsets.all(32),
                    child: CircularProgressIndicator(),
                  );
                },
                errorBuilder: (_, __, ___) => const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('No se pudo cargar la foto.'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _storeSummaryText() {
    final count = _bootstrap?.activeStores.length ?? 0;
    if (count == 0) {
      return 'Sin tiendas activas cargadas. Actualiza con internet.';
    }
    final nearest = _lastNearestStore;
    if (nearest != null) {
      final inside =
          nearest.distanceMeters <= nearest.store.allowedRadiusMeters;
      final storeName = nearest.store.chain.isEmpty
          ? nearest.store.name
          : '${nearest.store.chain} - ${nearest.store.name}';
      return '$storeName es la más cercana: ${nearest.distanceMeters.toStringAsFixed(1)} m. ${inside ? "Dentro del radio" : "Fuera del radio"}.';
    }
    return '$count tienda(s) activas cargadas. Al checar se usará la tienda más cercana dentro de 50 m.';
  }
}

class NearestStore {
  NearestStore({required this.store, required this.distanceMeters});

  final StoreInfo store;
  final double distanceMeters;
}

class PhaseOption {
  const PhaseOption(
    this.key,
    this.label, {
    this.requiredPrevious,
    this.verification = false,
    this.enabled = true,
  });

  final String key;
  final String label;
  final String? requiredPrevious;
  final bool verification;
  final bool enabled;

  PhaseOption copyWith({bool? enabled}) {
    return PhaseOption(
      key,
      label,
      requiredPrevious: requiredPrevious,
      verification: verification,
      enabled: enabled ?? this.enabled,
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 10),
            child,
          ],
        ),
      ),
    );
  }
}

class FrontCameraCapturePage extends StatefulWidget {
  const FrontCameraCapturePage({super.key});

  @override
  State<FrontCameraCapturePage> createState() => _FrontCameraCapturePageState();
}

class _FrontCameraCapturePageState extends State<FrontCameraCapturePage> {
  CameraController? _controller;
  Future<void>? _initializeFuture;
  String? _error;
  bool _capturing = false;

  @override
  void initState() {
    super.initState();
    _initializeFuture = _initializeCamera();
  }

  Future<void> _initializeCamera() async {
    try {
      final cameras = await availableCameras();
      CameraDescription? frontCamera;
      for (final camera in cameras) {
        if (camera.lensDirection == CameraLensDirection.front) {
          frontCamera = camera;
          break;
        }
      }
      if (frontCamera == null) {
        throw ApiException(
            'Este equipo no reporta una cámara frontal disponible.', 400);
      }

      final controller = CameraController(
        frontCamera,
        ResolutionPreset.medium,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );
      await controller.initialize();
      await controller.setFlashMode(FlashMode.off);

      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() => _controller = controller);
    } catch (err) {
      if (mounted) {
        setState(() => _error = err.toString().replaceFirst('Exception: ', ''));
      }
    }
  }

  Future<void> _takePhoto() async {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized || _capturing) {
      return;
    }
    setState(() => _capturing = true);
    try {
      final photo = await controller.takePicture();
      if (mounted) {
        Navigator.of(context).pop(photo);
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _capturing = false;
          _error = 'No se pudo tomar la foto. Intenta de nuevo.';
        });
      }
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Selfie de checada')),
      body: FutureBuilder<void>(
        future: _initializeFuture,
        builder: (context, snapshot) {
          final controller = _controller;
          if (_error != null) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.error_outline, size: 48),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center),
                    const SizedBox(height: 16),
                    FilledButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: const Text('Regresar'),
                    ),
                  ],
                ),
              ),
            );
          }
          if (snapshot.connectionState != ConnectionState.done ||
              controller == null ||
              !controller.value.isInitialized) {
            return const Center(child: CircularProgressIndicator());
          }
          return Stack(
            fit: StackFit.expand,
            children: [
              Center(
                child: AspectRatio(
                  aspectRatio: controller.value.aspectRatio,
                  child: CameraPreview(controller),
                ),
              ),
              Align(
                alignment: Alignment.bottomCenter,
                child: SafeArea(
                  minimum: const EdgeInsets.all(24),
                  child: FilledButton.icon(
                    onPressed: _capturing ? null : _takePhoto,
                    icon: const Icon(Icons.camera_alt),
                    label:
                        Text(_capturing ? 'Tomando foto...' : 'Tomar selfie'),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class ApiException implements Exception {
  ApiException(this.message, this.statusCode);

  final String message;
  final int statusCode;

  @override
  String toString() => message;
}

class AppUser {
  AppUser({required this.id, required this.fullName, required this.role});

  final int id;
  final String fullName;
  final String role;

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: _readInt(json['id']),
      fullName: json['full_name']?.toString() ?? '',
      role: json['role']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'full_name': fullName,
        'role': role,
      };
}

class StoreInfo {
  StoreInfo({
    required this.id,
    required this.chain,
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.allowedRadiusMeters,
    required this.timezone,
  });

  final int id;
  final String chain;
  final String name;
  final double latitude;
  final double longitude;
  final int allowedRadiusMeters;
  final String timezone;

  factory StoreInfo.fromJson(Map<String, dynamic> json) {
    return StoreInfo(
      id: _readInt(json['id']),
      chain: json['chain']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      latitude: _readDouble(json['latitude']),
      longitude: _readDouble(json['longitude']),
      allowedRadiusMeters:
          _readInt(json['allowed_radius_meters'], fallback: 50),
      timezone: json['timezone']?.toString() ?? 'America/Mexico_City',
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'chain': chain,
        'name': name,
        'latitude': latitude,
        'longitude': longitude,
        'allowed_radius_meters': allowedRadiusMeters,
        'timezone': timezone,
      };
}

class MobileBootstrap {
  MobileBootstrap({
    required this.activeStores,
    required this.todayChecks,
    required this.requiresLocationVerification,
    this.serverTime,
    this.todayDate,
  });

  final List<StoreInfo> activeStores;
  final List<TodayCheck> todayChecks;
  final bool requiresLocationVerification;
  final String? serverTime;
  final String? todayDate;

  factory MobileBootstrap.fromJson(Map<String, dynamic> json) {
    final stores = (json['active_stores'] ?? []) as List<dynamic>;
    final checks = (json['today_checks'] ?? []) as List<dynamic>;
    return MobileBootstrap(
      activeStores: stores
          .map((item) => StoreInfo.fromJson(item as Map<String, dynamic>))
          .toList(),
      todayChecks: checks
          .map((item) => TodayCheck.fromJson(item as Map<String, dynamic>))
          .toList(),
      requiresLocationVerification:
          json['requires_location_verification'] == true ||
              json['requires_location_verification'] == 1,
      serverTime: json['server_time']?.toString(),
      todayDate: json['today_date']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
        'active_stores': activeStores.map((item) => item.toJson()).toList(),
        'today_checks': todayChecks.map((item) => item.toJson()).toList(),
        'requires_location_verification': requiresLocationVerification,
        'server_time': serverTime,
        'today_date': todayDate,
      };
}

class TodayCheck {
  TodayCheck(
      {required this.phase,
      required this.checkedAt,
      required this.status,
      required this.checkDate});

  final String phase;
  final String checkedAt;
  final String status;
  final String checkDate;

  factory TodayCheck.fromJson(Map<String, dynamic> json) {
    return TodayCheck(
      phase: json['phase']?.toString() ?? '',
      checkedAt: json['checked_at']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
      checkDate: json['check_date']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'phase': phase,
        'checked_at': checkedAt,
        'status': status,
        'check_date': checkDate,
      };
}

class PendingCheck {
  PendingCheck({
    required this.localId,
    required this.storeId,
    required this.phase,
    required this.capturedAtDevice,
    required this.latitude,
    required this.longitude,
    required this.gpsAccuracyMeters,
    required this.gpsIsMocked,
    required this.distanceMetersClient,
    required this.deviceId,
    required this.photoBase64,
    this.rejectedAt,
    this.rejectedMessage,
  });

  final String localId;
  final int storeId;
  final String phase;
  final String capturedAtDevice;
  final double latitude;
  final double longitude;
  final double gpsAccuracyMeters;
  final bool gpsIsMocked;
  final double distanceMetersClient;
  final String deviceId;
  final String photoBase64;
  final DateTime? rejectedAt;
  final String? rejectedMessage;

  factory PendingCheck.fromJson(Map<String, dynamic> json) {
    return PendingCheck(
      localId: json['local_id']?.toString() ?? '',
      storeId: _readInt(json['store_id']),
      phase: json['phase']?.toString() ?? '',
      capturedAtDevice: json['captured_at_device']?.toString() ?? '',
      latitude: _readDouble(json['latitude']),
      longitude: _readDouble(json['longitude']),
      gpsAccuracyMeters: _readDouble(json['gps_accuracy_meters']),
      gpsIsMocked: json['gps_is_mocked'] == true,
      distanceMetersClient: _readDouble(json['distance_meters_client']),
      deviceId: json['device_id']?.toString() ?? '',
      photoBase64: json['photo_base64']?.toString() ?? '',
      rejectedAt: DateTime.tryParse(json['rejected_at']?.toString() ?? ''),
      rejectedMessage: json['rejected_message']?.toString(),
    );
  }

  PendingCheck markRejected(String message) {
    return PendingCheck(
      localId: localId,
      storeId: storeId,
      phase: phase,
      capturedAtDevice: capturedAtDevice,
      latitude: latitude,
      longitude: longitude,
      gpsAccuracyMeters: gpsAccuracyMeters,
      gpsIsMocked: gpsIsMocked,
      distanceMetersClient: distanceMetersClient,
      deviceId: deviceId,
      photoBase64: photoBase64,
      rejectedAt: DateTime.now(),
      rejectedMessage: message,
    );
  }

  Map<String, dynamic> toJson() => {
        'local_id': localId,
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'distance_meters_client': distanceMetersClient,
        'device_id': deviceId,
        'photo_base64': photoBase64,
        if (rejectedAt != null) 'rejected_at': rejectedAt!.toIso8601String(),
        if (rejectedMessage != null) 'rejected_message': rejectedMessage,
      };

  Map<String, dynamic> toApiJson() => {
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'device_time_iso': DateTime.now().toIso8601String(),
        'device_id': deviceId,
        'photo_base64': photoBase64,
      };

  Map<String, dynamic> toSyncJson() => {
        'local_id': localId,
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'device_time_iso': DateTime.now().toIso8601String(),
        'distance_meters_client': distanceMetersClient,
        'photo_base64': photoBase64,
      };
}

class CheckRecord {
  CheckRecord({
    required this.checkDate,
    required this.phase,
    required this.storeChain,
    required this.storeName,
    required this.distanceMeters,
    required this.status,
    required this.workedTime,
    required this.mealTime,
    required this.photoUrl,
  });

  final String checkDate;
  final String phase;
  final String storeChain;
  final String storeName;
  final double distanceMeters;
  final String status;
  final String workedTime;
  final String mealTime;
  final String photoUrl;

  String get storeLabel =>
      storeChain.isEmpty ? storeName : '$storeChain - $storeName';

  factory CheckRecord.fromJson(Map<String, dynamic> json) {
    return CheckRecord(
      checkDate: json['check_date']?.toString() ?? '',
      phase: json['phase']?.toString() ?? '',
      storeChain: json['store_chain']?.toString() ?? '',
      storeName: json['store_name']?.toString() ?? '',
      distanceMeters: _readDouble(json['distance_meters']),
      status: json['status']?.toString() ?? '',
      workedTime: json['tiempo_laborado']?.toString() ?? '',
      mealTime: json['tiempo_comida']?.toString() ?? '',
      photoUrl: json['photo_url']?.toString() ?? '',
    );
  }
}

class NoticeItem {
  NoticeItem({
    required this.id,
    required this.title,
    required this.body,
    required this.imageUrl,
    required this.publishedAt,
  });

  final int id;
  final String title;
  final String body;
  final String imageUrl;
  final String publishedAt;

  factory NoticeItem.fromJson(Map<String, dynamic> json) {
    return NoticeItem(
      id: _readInt(json['id']),
      title: json['title']?.toString() ?? '',
      body: json['body']?.toString() ?? '',
      imageUrl: json['image_url']?.toString() ?? '',
      publishedAt: json['published_at']?.toString() ?? '',
    );
  }
}

double distanceMeters(double lat1, double lng1, double lat2, double lng2) {
  const earth = 6371000.0;
  final dLat = _degToRad(lat2 - lat1);
  final dLng = _degToRad(lng2 - lng1);
  final a = pow(sin(dLat / 2), 2) +
      cos(_degToRad(lat1)) * cos(_degToRad(lat2)) * pow(sin(dLng / 2), 2);
  return earth * 2 * atan2(sqrt(a), sqrt(1 - a));
}

double _degToRad(double degrees) => degrees * pi / 180;

double _readDouble(dynamic value, {double fallback = 0}) {
  if (value is num) {
    return value.toDouble();
  }
  return double.tryParse(value?.toString() ?? '') ?? fallback;
}

int _readInt(dynamic value, {int fallback = 0}) {
  if (value is num) {
    return value.toInt();
  }
  return int.tryParse(value?.toString() ?? '') ?? fallback;
}

String _phaseLabel(String phase) {
  switch (phase) {
    case 'ingreso':
      return 'Ingreso';
    case 'salida_comer':
      return 'Salida a comer';
    case 'entrada_comer':
      return 'Entrada de comer';
    case 'salida':
      return 'Salida';
    case 'verificacion_ubicacion_1':
      return 'Verificación de ubicación 1';
    case 'verificacion_ubicacion_2':
      return 'Verificación de ubicación 2';
    case 'verificacion_ubicacion_3':
      return 'Verificación de ubicación 3';
    default:
      return phase;
  }
}

String _statusLabel(String status) {
  switch (status) {
    case 'valid':
      return 'Valida';
    case 'synced':
      return 'Sincronizada';
    case 'manual_review':
      return 'Revision manual';
    case 'blocked_out_of_range':
      return 'Fuera de rango';
    case 'rejected':
      return 'Rechazada';
    default:
      return status;
  }
}
